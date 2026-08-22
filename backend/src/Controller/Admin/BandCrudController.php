<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Band;
use App\Entity\ConcertBand;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * US-6/AC-6.5: read-only band list. `normalizedName` is shown deliberately (not hidden) — it is
 * what makes a dedup mistake (`App\Service\Concert\BandResolver`) visible to an operator.
 *
 * @extends AbstractAdminCrudController<Band>
 */
final class BandCrudController extends AbstractAdminCrudController
{
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
            ->setSearchFields(['name', 'normalizedName']);
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name');
        yield TextField::new('normalizedName');
        yield TextField::new('setlistfmMbid', 'setlist.fm MBID');
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

        // AC-6.2 (applies equally to AC-6.5's band-list count): one aggregate subquery, no N+1.
        $qb->addSelect(\sprintf(
            '(SELECT COUNT(cb.id) FROM %s cb WHERE cb.band = entity) AS HIDDEN concertCount',
            ConcertBand::class,
        ));

        return $qb;
    }
}
