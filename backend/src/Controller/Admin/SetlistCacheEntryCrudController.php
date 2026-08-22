<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SetlistCacheEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Read-only view of the durable cache tier (AC-11.4). `payload` is only shown on the detail page,
 * pretty-printed — it's the verbatim setlist.fm response (D-60), useful for an operator diagnosing
 * a normalizer bug, but too wide for the index list.
 *
 * @extends AbstractAdminCrudController<SetlistCacheEntry>
 */
final class SetlistCacheEntryCrudController extends AbstractAdminCrudController
{
    public static function getEntityFqcn(): string
    {
        return SetlistCacheEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Setlist cache entry')
            ->setEntityLabelInPlural('Setlist cache')
            ->setDefaultSort(['fetchedAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['cacheKey', 'endpoint']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('endpoint')->setChoices([
            'artist.search' => 'artist.search',
            'artist.get' => 'artist.get',
            'artist.setlists' => 'artist.setlists',
            'setlist.get' => 'setlist.get',
        ]));
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('cacheKey', 'Cache key');
        yield ChoiceField::new('endpoint')->setChoices([
            'artist.search' => 'artist.search',
            'artist.get' => 'artist.get',
            'artist.setlists' => 'artist.setlists',
            'setlist.get' => 'setlist.get',
        ]);
        yield DateTimeField::new('fetchedAt');
        yield DateTimeField::new('staleAfter', 'Stale after')->formatValue(
            static fn (mixed $v): string => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : 'never (immutable)',
        );
        yield IntegerField::new('httpStatus', 'HTTP status');
        yield TextareaField::new('payload')->onlyOnDetail()->formatValue(
            static fn (mixed $v): string => is_array($v) ? (json_encode($v, \JSON_PRETTY_PRINT) ?: '') : '',
        );
    }
}
