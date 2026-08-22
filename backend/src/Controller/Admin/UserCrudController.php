<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Concert;
use App\Entity\User;
use App\Field\MaskedEmailField;
use App\Repository\RefreshTokenRepository;
use App\Security\Admin\AdminUser;
use App\Service\Admin\AuditLogger;
use App\Service\Admin\EmailMasker;
use App\Service\Admin\UserEraser;
use App\Service\Security\RateLimiterGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * US-6/US-7/US-9: read-only list + detail (AC-6.1, AC-6.6, AC-6.7), plus the three narrow write
 * actions this feature allows — suspend, unsuspend, hard delete — and the reveal-email action
 * (US-9). No other write action exists here (AC-7.7); `configureActions()` from
 * {@see AbstractAdminCrudController} already disabled NEW/EDIT/DELETE/BATCH_DELETE.
 *
 * @extends AbstractAdminCrudController<User>
 */
final class UserCrudController extends AbstractAdminCrudController
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly AuditLogger $auditLogger,
        private readonly UserEraser $userEraser,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RateLimiterGuard $rateLimiterGuard,
        #[Autowire(service: 'limiter.admin_reveal_email')]
        private readonly RateLimiterFactory $revealEmailLimiter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['email']);
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield MaskedEmailField::new('email');
        yield DateTimeField::new('createdAt', 'Registered');
        yield BooleanField::new('emailVerified')->formatValue(static fn (mixed $v, User $u): bool => $u->isEmailVerified());
        yield BooleanField::new('isActive', 'Active')->renderAsSwitch(false);
        yield IntegerField::new('concertCount', 'Concerts')->onlyOnIndex();
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = $this->container->get(EntityRepositoryInterface::class)
            ->createQueryBuilder($searchDto, $entityDto, $fields, $filters);

        // AC-6.2: a single aggregate subquery for the whole page, not an N+1 loop per row.
        $qb->addSelect(\sprintf('(SELECT COUNT(c.id) FROM %s c WHERE c.owner = entity) AS HIDDEN concertCount', Concert::class));

        return $qb;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);

        $toggleActive = Action::new('toggleActive', 'Suspend / Unsuspend')
            ->linkToCrudAction('confirmToggleActive');
        $revealEmail = Action::new('revealEmail', 'Reveal email')
            ->linkToCrudAction('confirmRevealEmail');
        $deleteUser = Action::new('eraseUser', 'Delete (irreversible)')
            ->linkToCrudAction('confirmDelete');

        return $actions
            ->add(Crud::PAGE_DETAIL, $toggleActive)
            ->add(Crud::PAGE_INDEX, $toggleActive)
            ->add(Crud::PAGE_DETAIL, $revealEmail)
            ->add(Crud::PAGE_INDEX, $revealEmail)
            ->add(Crud::PAGE_DETAIL, $deleteUser);
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(path: '/{entityId}/toggle-active/confirm', name: '_toggle_active_confirm')]
    public function confirmToggleActive(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $user = $this->requireUser($context);

        return $this->render('admin/user/confirm_toggle_active.html.twig', [
            'user' => $user,
            'masked_email' => EmailMasker::mask($user->getEmail()),
            'action_path' => $urlGenerator->setController(self::class)->setAction('performToggleActive')->setEntityId($user->getId())->generateUrl(),
            'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($user->getId())->generateUrl(),
        ]);
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(path: '/{entityId}/toggle-active', name: '_toggle_active', options: ['methods' => ['POST']])]
    public function performToggleActive(Request $request, AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $user = $this->requireUser($context);
        $actor = $this->requireActor();

        // Not a form_login/scheb-managed route, so it isn't covered by their CSRF handling —
        // validated explicitly (devops-security-engineer review, 2026-08-21).
        if (!$this->isCsrfTokenValid('admin_user_action', (string) $request->request->get('_csrf_token', ''))) {
            return $this->render('admin/user/confirm_toggle_active.html.twig', [
                'user' => $user,
                'masked_email' => EmailMasker::mask($user->getEmail()),
                'action_path' => $urlGenerator->setController(self::class)->setAction('performToggleActive')->setEntityId($user->getId())->generateUrl(),
                'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($user->getId())->generateUrl(),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $oldValue = $user->isActive() ? 'true' : 'false';
        $newActive = !$user->isActive();

        $user->setActive($newActive);
        $this->entityManager->flush();

        if (!$newActive) {
            // AC-7.2: suspending also revokes every refresh token, so an already-issued session
            // cannot outlive the suspension.
            $this->refreshTokenRepository->revokeAllForUser($user, new \DateTimeImmutable());
        }

        $this->auditLogger->log(
            actor: $actor,
            action: $newActive ? 'unsuspend_user' : 'suspend_user',
            subjectType: 'User',
            subjectId: $user->getId() ?? 0,
            field: 'isActive',
            oldValue: $oldValue,
            newValue: $newActive ? 'true' : 'false',
        );

        return new RedirectResponse(
            $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($user->getId())->generateUrl(),
        );
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(path: '/{entityId}/delete/confirm', name: '_delete_confirm')]
    public function confirmDelete(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $user = $this->requireUser($context);

        return $this->render('admin/user/confirm_delete.html.twig', [
            'user' => $user,
            'masked_email' => EmailMasker::mask($user->getEmail()),
            'action_path' => $urlGenerator->setController(self::class)->setAction('performDelete')->setEntityId($user->getId())->generateUrl(),
            'index_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl(),
        ]);
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(path: '/{entityId}/delete/perform', name: '_delete_perform', options: ['methods' => ['POST']])]
    public function performDelete(Request $request, AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $user = $this->requireUser($context);
        $actor = $this->requireActor();

        // Not a form_login/scheb-managed route, so it isn't covered by their CSRF handling —
        // validated explicitly (devops-security-engineer review, 2026-08-21).
        if (!$this->isCsrfTokenValid('admin_user_action', (string) $request->request->get('_csrf_token', ''))) {
            return $this->render('admin/user/confirm_delete.html.twig', [
                'user' => $user,
                'masked_email' => EmailMasker::mask($user->getEmail()),
                'action_path' => $urlGenerator->setController(self::class)->setAction('performDelete')->setEntityId($user->getId())->generateUrl(),
                'index_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl(),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $confirmedId = (string) $request->request->get('confirm_id', '');
        if ($confirmedId !== (string) $user->getId()) {
            return $this->render('admin/user/confirm_delete.html.twig', [
                'user' => $user,
                'masked_email' => EmailMasker::mask($user->getEmail()),
                'action_path' => $urlGenerator->setController(self::class)->setAction('performDelete')->setEntityId($user->getId())->generateUrl(),
                'index_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl(),
                'error' => 'The id you typed did not match — nothing was deleted.',
            ], new Response(status: 422));
        }

        $this->userEraser->erase($user, $actor);

        return new RedirectResponse($urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
    }

    /**
     * AC-9.2: the reveal itself is a POST, CSRF-protected exactly like suspend/delete (devops-
     * security-engineer review, 2026-08-21: the previous single GET route made a sensitive, audited,
     * rate-limited action triggerable by a plain link — including a cross-site one, since a top-level
     * GET navigation still carries a `SameSite=Lax` cookie). This confirmation step performs no
     * side effect: nothing is revealed, audited or rate-limited until the POST below succeeds.
     *
     * @param AdminContext<User> $context
     */
    #[AdminRoute(path: '/{entityId}/reveal-email/confirm', name: '_reveal_email_confirm')]
    public function confirmRevealEmail(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $user = $this->requireUser($context);

        return $this->render('admin/user/confirm_reveal_email.html.twig', [
            'user' => $user,
            'masked_email' => EmailMasker::mask($user->getEmail()),
            'action_path' => $urlGenerator->setController(self::class)->setAction('revealEmail')->setEntityId($user->getId())->generateUrl(),
            'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($user->getId())->generateUrl(),
        ]);
    }

    /**
     * AC-9.2–AC-9.6: audited, rate-limited (30/hr per admin session), the response carries
     * `Cache-Control: no-store` (`App\EventSubscriber\AdminCacheControlSubscriber` applies that to
     * every admin response), and the revealed value is returned in this response only — never stored
     * or cached.
     *
     * @param AdminContext<User> $context
     */
    #[AdminRoute(path: '/{entityId}/reveal-email', name: '_reveal_email', options: ['methods' => ['POST']])]
    public function revealEmail(Request $request, AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $user = $this->requireUser($context);
        $actor = $this->requireActor();

        if (!$this->isCsrfTokenValid('admin_user_action', (string) $request->request->get('_csrf_token', ''))) {
            return $this->render('admin/user/confirm_reveal_email.html.twig', [
                'user' => $user,
                'masked_email' => EmailMasker::mask($user->getEmail()),
                'action_path' => $urlGenerator->setController(self::class)->setAction('revealEmail')->setEntityId($user->getId())->generateUrl(),
                'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($user->getId())->generateUrl(),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $this->rateLimiterGuard->consume($this->revealEmailLimiter, (string) ($actor->getId() ?? 'unknown'));

        $this->auditLogger->log(
            actor: $actor,
            action: 'reveal_email',
            subjectType: 'User',
            subjectId: $user->getId() ?? 0,
        );

        return $this->render('admin/user/revealed_email.html.twig', [
            'user' => $user,
            'email' => $user->getEmail(),
        ]);
    }

    /** @param AdminContext<User> $context */
    private function requireUser(AdminContext $context): User
    {
        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof User) {
            throw new \LogicException('Expected a User entity.');
        }

        return $entity;
    }

    private function requireActor(): User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$user instanceof AdminUser) {
            throw new \LogicException('No authenticated admin.');
        }

        return $user->getWrappedUser();
    }
}
