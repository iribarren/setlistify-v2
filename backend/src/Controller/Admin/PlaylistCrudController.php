<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Playlist;
use App\Field\MaskedEmailField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * `/admin` "Playlists" screen — read-only list of generated playlists, with the report summary
 * (spec 2026-08-23-spike-playlist-pipeline.md §8, D-142). No write actions anywhere, not even a
 * retry: deleting this row never deletes the provider-side playlist either (see `Playlist`'s own
 * docblock, D-151) — this screen doesn't even expose that.
 *
 * Reads across every owner directly via Doctrine/EasyAdmin, same shape as `ConcertCrudController`
 * (D-47) — `PlaylistOwnerExtension` is never touched.
 *
 * The report summary is rendered through a *virtual* field (`reportSummaryPretty`, not the real
 * `reportSummary` property) — see `PlaylistGenerationJobCrudController`'s docblock for why:
 * `Field::new()` on a real mapped property triggers EasyAdmin's Doctrine-type auto-guessing, which
 * silently discards `formatValue()` and (for a json column) mis-renders the raw array via a plain
 * `implode()`.
 *
 * @extends AbstractAdminCrudController<Playlist>
 */
final class PlaylistCrudController extends AbstractAdminCrudController
{
    public static function getEntityFqcn(): string
    {
        return Playlist::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Playlist')
            ->setEntityLabelInPlural('Playlists')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('providerKey', 'Provider'));
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Created');
        yield MaskedEmailField::new('owner.email', 'User');
        yield AssociationField::new('concert')->formatValue(
            static fn (mixed $v, Playlist $playlist): string => \sprintf('Concert #%d (%s)', (int) $playlist->getConcert()->getId(), $playlist->getConcert()->getDate()->format('Y-m-d')),
        );
        yield TextField::new('providerKey', 'Provider');
        yield TextField::new('name');
        yield TextField::new('providerPlaylistId', 'Provider playlist id')->hideOnIndex();
        yield TextField::new('externalUrl', 'External URL')->hideOnIndex();
        yield Field::new('reportSummaryPretty', 'Report summary')->setTemplatePath('admin/field/raw_html.html.twig')->formatValue(
            static fn (mixed $v, Playlist $playlist): string => self::formatReportSummary($playlist),
        );
        yield DateTimeField::new('updatedAt')->onlyOnIndex();
    }

    private static function formatReportSummary(Playlist $playlist): string
    {
        $entries = $playlist->getReportSummary();
        if ([] === $entries) {
            return '<em>No report entries.</em>';
        }

        $items = '';
        foreach ($entries as $entry) {
            $params = [] !== $entry['params'] ? ' '.json_encode($entry['params']) : '';
            $items .= \sprintf('<li>%s%s</li>', htmlspecialchars((string) $entry['code'], \ENT_QUOTES), htmlspecialchars($params, \ENT_QUOTES));
        }

        return '<ul>'.$items.'</ul>';
    }
}
