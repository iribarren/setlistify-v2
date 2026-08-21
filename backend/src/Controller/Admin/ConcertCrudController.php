<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Concert;
use App\Entity\ConcertBand;
use App\Field\MaskedEmailField;
use App\Service\Admin\EmailMasker;
use Doctrine\Common\Collections\Collection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;

/**
 * US-6/AC-6.4: read-only concert list. `owner` is masked (D-51); lineup is displayed ordered by
 * `ConcertBand.billingOrder` ascending — 0 is the headliner — which `Concert::$concertBands`
 * already guarantees via its `#[ORM\OrderBy]` (no re-sorting needed here).
 *
 * AC-6.8: this reads through Doctrine/EasyAdmin directly, never through an API Platform provider or
 * `ConcertOwnerExtension` — the admin sees concerts across every owner by design (D-47).
 *
 * @extends AbstractAdminCrudController<Concert>
 */
final class ConcertCrudController extends AbstractAdminCrudController
{
    public static function getEntityFqcn(): string
    {
        return Concert::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Concert')
            ->setEntityLabelInPlural('Concerts')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('owner'))
            ->add(DateTimeFilter::new('pastAfter', 'Past-after boundary (upcoming/past)'));
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('date')->setFormat('yyyy-MM-dd');
        yield TextField::new('timezone');
        yield TextField::new('venue.name', 'Venue')->formatValue(static fn (mixed $v, Concert $c): string => $c->getVenue()->getName() ?? '—');
        yield AssociationField::new('owner')->formatValue(
            static fn (mixed $v, Concert $c): string => EmailMasker::mask($c->getOwner()->getEmail()),
        );
        yield TextField::new('lineup', 'Lineup (headliner first)')->onlyOnIndex()->formatValue(
            static function (mixed $v, Concert $c): string {
                /** @var Collection<int, ConcertBand> $lineup */
                $lineup = $c->getConcertBands();

                return implode(' → ', array_map(
                    static fn (ConcertBand $cb): string => $cb->getBand()->getName(),
                    $lineup->toArray(),
                ));
            },
        );
        yield DateTimeField::new('createdAt');
    }
}
