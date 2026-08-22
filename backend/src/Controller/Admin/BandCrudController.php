<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Band;
use App\Entity\ConcertBand;
use App\Repository\SetlistRepository;
use App\Security\Admin\AdminUser;
use App\Service\Admin\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * US-6/AC-6.5: read-only band list plus setlist.fm resolution state (AC-2.6, AC-11.4). Two audited
 * writes only (AC-11.5, D-67) — correcting a wrong MBID, and clearing a band's cached setlist
 * associations after doing so. `normalizedName` is shown deliberately (not hidden) — it is what
 * makes a dedup mistake (`App\Service\Concert\BandResolver`) visible to an operator.
 *
 * @extends AbstractAdminCrudController<Band>
 */
final class BandCrudController extends AbstractAdminCrudController
{
    public function __construct(
        private readonly SetlistRepository $setlistRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Band::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Band')
            ->setEntityLabelInPlural('Bands')
            ->setDefaultSort(['name' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['name', 'normalizedName', 'setlistfmMbid']);
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name');
        yield TextField::new('normalizedName');
        yield TextField::new('setlistfmMbid', 'setlist.fm MBID');
        yield TextField::new('setlistfmName', 'setlist.fm canonical name')->onlyOnDetail();
        yield TextField::new('setlistfmResolutionState', 'Resolution state');
        yield DateTimeField::new('setlistfmCheckedAt', 'Last checked')->onlyOnDetail();
        yield DateTimeField::new('setlistfmResolvedAt', 'Resolved at')->onlyOnDetail();
        yield DateTimeField::new('createdAt');
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

        $qb->addSelect(\sprintf(
            '(SELECT COUNT(cb.id) FROM %s cb WHERE cb.band = entity) AS HIDDEN concertCount',
            ConcertBand::class,
        ));

        return $qb;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);

        $correctMbid = Action::new('correctMbid', 'Correct MBID')->linkToCrudAction('confirmCorrectMbid');
        $clearCache = Action::new('clearSetlistCache', 'Clear setlist cache')->linkToCrudAction('confirmClearCache');

        return $actions
            ->add(Crud::PAGE_DETAIL, $correctMbid)
            ->add(Crud::PAGE_INDEX, $correctMbid)
            ->add(Crud::PAGE_DETAIL, $clearCache)
            ->add(Crud::PAGE_INDEX, $clearCache);
    }

    /** @param AdminContext<Band> $context */
    #[AdminRoute(path: '/{entityId}/correct-mbid/confirm', name: '_correct_mbid_confirm')]
    public function confirmCorrectMbid(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $band = $this->requireBand($context);

        return $this->render('admin/band/correct_mbid.html.twig', [
            'band' => $band,
            'action_path' => $urlGenerator->setController(self::class)->setAction('performCorrectMbid')->setEntityId($band->getId())->generateUrl(),
            'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
        ]);
    }

    /** @param AdminContext<Band> $context */
    #[AdminRoute(path: '/{entityId}/correct-mbid', name: '_correct_mbid', options: ['methods' => ['POST']])]
    public function performCorrectMbid(Request $request, AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $band = $this->requireBand($context);
        $actor = $this->requireActor();

        if (!$this->isCsrfTokenValid('admin_band_action', (string) $request->request->get('_csrf_token', ''))) {
            return $this->render('admin/band/correct_mbid.html.twig', [
                'band' => $band,
                'action_path' => $urlGenerator->setController(self::class)->setAction('performCorrectMbid')->setEntityId($band->getId())->generateUrl(),
                'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $newMbid = trim((string) $request->request->get('mbid', ''));
        if ('' === $newMbid) {
            return $this->render('admin/band/correct_mbid.html.twig', [
                'band' => $band,
                'action_path' => $urlGenerator->setController(self::class)->setAction('performCorrectMbid')->setEntityId($band->getId())->generateUrl(),
                'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
                'error' => 'An MBID is required.',
            ], new Response(status: 422));
        }

        $oldMbid = $band->getSetlistfmMbid() ?? '(none)';
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $band->resolveTo($newMbid, $band->getSetlistfmName(), $now);
        $this->entityManager->flush();

        $this->auditLogger->log(
            actor: $actor,
            action: 'correct_band_mbid',
            subjectType: 'Band',
            subjectId: $band->getId() ?? 0,
            field: 'setlistfmMbid',
            oldValue: $oldMbid,
            newValue: $newMbid,
        );

        return new RedirectResponse(
            $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
        );
    }

    /** @param AdminContext<Band> $context */
    #[AdminRoute(path: '/{entityId}/clear-setlist-cache/confirm', name: '_clear_cache_confirm')]
    public function confirmClearCache(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $band = $this->requireBand($context);

        return $this->render('admin/band/clear_cache_confirm.html.twig', [
            'band' => $band,
            'setlist_count' => $this->setlistRepository->countForBand($band),
            'action_path' => $urlGenerator->setController(self::class)->setAction('performClearCache')->setEntityId($band->getId())->generateUrl(),
            'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
        ]);
    }

    /** @param AdminContext<Band> $context */
    #[AdminRoute(path: '/{entityId}/clear-setlist-cache', name: '_clear_cache', options: ['methods' => ['POST']])]
    public function performClearCache(Request $request, AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        $band = $this->requireBand($context);
        $actor = $this->requireActor();

        if (!$this->isCsrfTokenValid('admin_band_action', (string) $request->request->get('_csrf_token', ''))) {
            return $this->render('admin/band/clear_cache_confirm.html.twig', [
                'band' => $band,
                'setlist_count' => $this->setlistRepository->countForBand($band),
                'action_path' => $urlGenerator->setController(self::class)->setAction('performClearCache')->setEntityId($band->getId())->generateUrl(),
                'detail_path' => $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $removed = $this->setlistRepository->deleteAllForBand($band);

        $this->auditLogger->log(
            actor: $actor,
            action: 'clear_band_setlist_cache',
            subjectType: 'Band',
            subjectId: $band->getId() ?? 0,
            field: 'setlists',
            oldValue: (string) $removed,
            newValue: '0',
        );

        return new RedirectResponse(
            $urlGenerator->setController(self::class)->setAction(Crud::PAGE_DETAIL)->setEntityId($band->getId())->generateUrl(),
        );
    }

    /** @param AdminContext<Band> $context */
    private function requireBand(AdminContext $context): Band
    {
        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof Band) {
            throw new \LogicException('Expected a Band entity.');
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
