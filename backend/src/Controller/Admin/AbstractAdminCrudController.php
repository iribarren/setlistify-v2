<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

/**
 * D-46: EasyAdmin's own {@see AbstractCrudController::configureFields()} has a non-abstract
 * default (it falls back to exposing every entity property) — the mechanism AC-10.3's rendered-HTML
 * crawl exists to catch.
 *
 * PHP does not allow re-declaring an inherited *concrete* method as `abstract` in a subclass (a
 * genuine compile-error enforcement, as the spec's "D-46" originally called for, is therefore not
 * reachable here — this is a deliberate, documented deviation). Instead, this base class overrides
 * `configureFields()` with an implementation that unconditionally throws: every concrete controller
 * MUST override it again to do anything at all, so the "expose everything" default is unreachable
 * in practice, and `AC-10.4`'s test asserts the throw directly.
 *
 * Every backoffice list is read-only by default (AC-6.6, AC-7.7): `NEW`/`EDIT`/`DELETE` are
 * disabled here so a new controller has to *deliberately* re-enable a write action, rather than
 * inherit one by omission. `User`'s suspend/unsuspend/delete are bespoke page actions
 * (`UserCrudController`), not EasyAdmin's generic edit/delete.
 *
 * @template TEntity of object
 *
 * @extends AbstractCrudController<TEntity>
 */
abstract class AbstractAdminCrudController extends AbstractCrudController
{
    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        throw new \LogicException(\sprintf('%s must override configureFields() with an explicit field allowlist — the base implementation intentionally throws instead of falling back to EasyAdmin\'s "expose everything" default (D-46).', static::class));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }
}
