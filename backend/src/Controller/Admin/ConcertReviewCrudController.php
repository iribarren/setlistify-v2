<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ConcertReview;
use App\Service\Admin\EmailMasker;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

/**
 * D-243: the admin sees THAT a review exists, its rating and its timestamps — never the body, not
 * even truncated. Same instinct as spec 08's digest-only audit values: the operator gets what an
 * abuse report or an erasure request requires, and no more, so a compromised admin session is not a
 * compromised diary.
 *
 * Read-only (`AbstractAdminCrudController`'s default — no `new`/`edit`/`delete` re-enabled here),
 * matching `ConcertCrudController`/`SetlistCacheEntryCrudController`. `owner` is masked (D-51), same
 * as the `Concert` list.
 *
 * @extends AbstractAdminCrudController<ConcertReview>
 */
final class ConcertReviewCrudController extends AbstractAdminCrudController
{
    public static function getEntityFqcn(): string
    {
        return ConcertReview::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Concert review')
            ->setEntityLabelInPlural('Concert reviews')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('owner'))
            ->add(EntityFilter::new('concert'))
            ->add(NumericFilter::new('rating'));
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('owner')->formatValue(
            static fn (mixed $v, ConcertReview $r): string => EmailMasker::mask($r->getOwner()->getEmail()),
        );
        yield AssociationField::new('concert');
        yield IntegerField::new('rating');
        yield BooleanField::new('hasNotes', 'Has notes')->renderAsSwitch(false)->formatValue(
            static fn (mixed $v, ConcertReview $r): bool => null !== $r->getNotes() && '' !== trim($r->getNotes()),
        );
        // AC-10.4/D-243: length only — the body itself never renders here, not even truncated.
        yield IntegerField::new('notesLength', 'Notes length (graphemes)')->formatValue(
            static fn (mixed $v, ConcertReview $r): int => null !== $r->getNotes() ? grapheme_strlen($r->getNotes()) ?: 0 : 0,
        );
        yield TextField::new('highlightTitle', 'Highlight')->onlyOnIndex();
        yield DateTimeField::new('createdAt');
        yield DateTimeField::new('updatedAt');
    }
}
