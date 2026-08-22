<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProviderSetting;
use App\Security\Admin\AdminUser;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderSettingValidationException;
use App\Service\Provider\ProviderSettingWriter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * `/admin` "Providers" section (US-1, US-2, US-3). **EDIT only** (AC-3.6) — `NEW`/`DELETE`/
 * `BATCH_DELETE` stay disabled from `AbstractAdminCrudController`: rows come from the migration
 * seed (D-102), and deleting one would leave `ProviderRegistry` with a hole rather than a decision.
 *
 * **Every write goes through `ProviderSettingWriter`, never a direct flush** — {@see
 * self::updateEntity()} overrides EasyAdmin's default (`persist()` + `flush()`) with a call to the
 * writer instead, so every admin edit is audited and invalidates the registry cache exactly the way
 * a hypothetical future API write would (AC-8.1, US-10). This is also why `getEntityFqcn()` below
 * is the one place outside `ProviderRegistry`/`ProviderSettingWriter` allowed to name
 * `ProviderSetting` — see that entity's docblock.
 *
 * @extends AbstractAdminCrudController<ProviderSetting>
 */
final class ProviderSettingCrudController extends AbstractAdminCrudController
{
    public function __construct(
        private readonly ProviderSettingWriter $writer,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ProviderSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Provider')
            ->setEntityLabelInPlural('Providers')
            ->setDefaultSort(['provider' => 'ASC'])
            ->setPaginatorPageSize(25);
    }

    /**
     * AC-3.6: EDIT re-enabled deliberately and only. Deliberately does NOT call
     * `parent::configureActions()`: EasyAdmin's `Actions::disable()` is append-only (there is no
     * `enable()` counterpart in this version to undo it), so re-enabling EDIT means building the
     * disabled set directly rather than disabling everything and trying to walk EDIT back.
     */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE);
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('provider')
            ->setFormTypeOption('disabled', true)
            ->setHelp('Configured providers come from the migration seed — this screen edits behaviour, never adds or removes a provider.');

        // AC-3.4: enabled's help text states the disable is graceful, visible without hovering.
        yield BooleanField::new('enabled')
            ->setHelp('Disabling is graceful: linked accounts and playlists survive untouched, and the provider can be re-enabled with no action from the user (US-4, US-5). If this provider is currently the default, disabling it clears the default rather than promoting another one.');

        // AC-3.1-AC-3.3: playbackMode's help text names the legal consequence at the moment of the click.
        yield ChoiceField::new('playbackMode')
            ->setFormType(\Symfony\Component\Form\Extension\Core\Type\EnumType::class)
            ->setFormTypeOptions([
                'class' => PlaybackMode::class,
                'choice_label' => static fn (PlaybackMode $mode): string => match ($mode) {
                    PlaybackMode::Embed => 'Embed — plays audio in-app',
                    PlaybackMode::Deeplink => 'Deep link — hands off to the provider app',
                    PlaybackMode::Off => 'Off — no playback surface',
                },
            ])
            ->setHelp(
                'Runtime configuration, not code — changes take effect on the next request, no deploy. '
                .'"embed" plays the provider\'s audio in-app: likely a Streaming SDA, and commercial use is '
                .'NOT permitted at all under this provider\'s developer policy. "deeplink" hands off playback '
                .'to the provider\'s own app: a Non-Streaming SDA, where advertising, sponsorship and paid '
                .'access ARE permitted. "off" removes the playback surface entirely. If the app is ever '
                .'monetized, "embed" is a policy violation for this row\'s provider — see '
                .'docs/external-apis.md (the section named after this row\'s "provider" value above) for the '
                .'full position.',
            );

        yield BooleanField::new('isDefault')
            ->setHelp('Pre-selected when a user has more than one linked provider. Cannot be set on a disabled provider; disabling the current default clears this instead of promoting another one (at most one provider may be default — enforced at the database level).');

        yield TextareaField::new('notes')
            ->setHelp('Internal operational note only — never shown to users, never returned by any API response, digested (not shown in the clear) in the audit log.')
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt')->onlyOnIndex();
        yield DateTimeField::new('createdAt')->onlyOnDetail();
    }

    /**
     * Overrides EasyAdmin's default `persist()` + `flush()` (AbstractCrudController::updateEntity())
     * so every admin write is routed through `ProviderSettingWriter` — the only write path (AC-8.1).
     */
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        // The class-level `@extends AbstractAdminCrudController<ProviderSetting>` generic already
        // guarantees $entityInstance is a ProviderSetting here — PHPStan proves it, so no runtime
        // instanceof check is added (same reasoning this codebase already applies to other
        // container-proven types).
        try {
            $this->writer->update(
                provider: $entityInstance->getProvider(),
                enabled: $entityInstance->isEnabled(),
                playbackMode: $entityInstance->getPlaybackMode(),
                isDefault: $entityInstance->isDefault(),
                notes: $entityInstance->getNotes(),
                actor: $this->requireActor(),
            );
        } catch (ProviderSettingValidationException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
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
