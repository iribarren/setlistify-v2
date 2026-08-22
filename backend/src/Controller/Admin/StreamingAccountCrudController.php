<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\StreamingAccount;
use App\Repository\StreamingAccountRepository;
use App\Security\Admin\AdminUser;
use App\Service\Admin\AuditLogger;
use App\Service\Admin\EmailMasker;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * D-84, AC-7.4, AC-7.5: read-only by default (inherited from `AbstractAdminCrudController`), plus
 * exactly one audited write — unlink on a user's behalf. `configureFields()` enumerates every field
 * explicitly; there is no `accessToken`/`refreshToken` field here, ever — that is what makes a
 * token leaking into this screen structurally impossible rather than merely unintentional (D-46).
 *
 * @extends AbstractAdminCrudController<StreamingAccount>
 */
final class StreamingAccountCrudController extends AbstractAdminCrudController
{
    public function __construct(
        private readonly StreamingAccountRepository $repository,
        private readonly AuditLogger $auditLogger,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return StreamingAccount::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Streaming account')
            ->setEntityLabelInPlural('Streaming accounts')
            ->setDefaultSort(['linkedAt' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('user')->formatValue(
            static fn (mixed $v, StreamingAccount $a): string => EmailMasker::mask($a->getUser()->getEmail()),
        );
        yield TextField::new('provider');
        yield TextField::new('providerDisplayName', 'Display name');
        yield TextField::new('providerAccountId', 'Provider account id');
        yield ArrayField::new('scopes')->onlyOnDetail();
        yield TextField::new('status');
        yield DateTimeField::new('linkedAt');
        yield DateTimeField::new('updatedAt')->onlyOnDetail();
        // Deliberately absent: accessToken, refreshToken, expiresAt (AC-7.4 — no token field, and
        // no expiry value that would let an admin infer one, same reasoning as AC-2.3's API response).
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);

        $unlink = Action::new('unlink', 'Unlink')->linkToCrudAction('confirmUnlink');

        return $actions
            ->add(Crud::PAGE_DETAIL, $unlink)
            ->add(Crud::PAGE_INDEX, $unlink);
    }

    /** @param AdminContext<StreamingAccount> $context */
    #[AdminRoute(path: '/{entityId}/unlink/confirm', name: '_unlink_confirm')]
    public function confirmUnlink(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $account = $this->requireAccount($context);

        return $this->render('admin/streaming_account/unlink_confirm.html.twig', [
            'account' => $account,
            'action_path' => $urlGenerator->setController(self::class)->setAction('performUnlink')->setEntityId($account->getId())->generateUrl(),
            'index_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl(),
        ]);
    }

    /** @param AdminContext<StreamingAccount> $context */
    #[AdminRoute(path: '/{entityId}/unlink', name: '_unlink', options: ['methods' => ['POST']])]
    public function performUnlink(Request $request, AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $account = $this->requireAccount($context);
        $actor = $this->requireActor();

        if (!$this->isCsrfTokenValid('admin_streaming_account_action', (string) $request->request->get('_csrf_token', ''))) {
            return $this->render('admin/streaming_account/unlink_confirm.html.twig', [
                'account' => $account,
                'action_path' => $urlGenerator->setController(self::class)->setAction('performUnlink')->setEntityId($account->getId())->generateUrl(),
                'index_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl(),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $accountId = $account->getId() ?? 0;
        $provider = $account->getProvider();

        $this->repository->remove($account);

        $this->auditLogger->log(
            actor: $actor,
            action: 'unlink_streaming_account',
            subjectType: 'StreamingAccount',
            subjectId: $accountId,
            field: 'provider',
            oldValue: $provider,
            newValue: null,
        );

        return new RedirectResponse($urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
    }

    /** @param AdminContext<StreamingAccount> $context */
    private function requireAccount(AdminContext $context): StreamingAccount
    {
        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof StreamingAccount) {
            throw new \LogicException('Expected a StreamingAccount entity.');
        }

        return $entity;
    }

    private function requireActor(): \App\Entity\User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$user instanceof AdminUser) {
            throw new \LogicException('No authenticated admin.');
        }

        return $user->getWrappedUser();
    }
}
