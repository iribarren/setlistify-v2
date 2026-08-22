<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditLogEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * AC-12.5: the audit log, read-only, newest first, filterable by action and subject type. No edit,
 * no delete action anywhere — enforced twice over: the base controller disables them by default,
 * and `App\EventSubscriber\AuditLogAppendOnlySubscriber` would reject them at the ORM level even if
 * a future change re-enabled the buttons (AC-12.4).
 *
 * @extends AbstractAdminCrudController<AuditLogEntry>
 */
final class AuditLogEntryCrudController extends AbstractAdminCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditLogEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audit log entry')
            ->setEntityLabelInPlural('Audit log')
            ->setDefaultSort(['occurredAt' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('action'))
            ->add(TextFilter::new('subjectType'));
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('occurredAt');
        yield IdField::new('actorId');
        yield TextField::new('actorLabel');
        yield TextField::new('action');
        yield TextField::new('subjectType');
        yield TextField::new('subjectId');
        yield TextField::new('field')->hideOnIndex();
        yield TextField::new('oldValue')->hideOnIndex();
        yield TextField::new('newValue')->hideOnIndex();
        yield TextField::new('ipAddress');
        yield TextField::new('userAgent')->hideOnIndex();
    }
}
