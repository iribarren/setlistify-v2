<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Field\MaskedEmailField;
use App\Repository\PlaylistRepository;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\FailureReason;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ReportCode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * `/admin` "Playlist generation" screen (spec 2026-08-23-spike-playlist-pipeline.md §8, D-141,
 * D-142). Read-only, always — not even a retry button (D-142): a stuck or failed job is fixed by
 * the pipeline's own resumption/expiry commands (`app:playlist:resume-blocked`,
 * `app:playlist:expire-jobs`), never by an admin click.
 *
 * AC (implied by D-142): this reads across every owner directly via Doctrine/EasyAdmin, the same
 * shape as `ConcertCrudController` (D-47) — `PlaylistGenerationJobOwnerExtension` is never touched.
 *
 * Every computed/enum/json display column below is a deliberately *virtual* `Field` — named so it
 * does NOT match a real mapped property — rather than reusing the real property name. EasyAdmin's
 * `FieldCollection` auto-guesses a concrete field class from the Doctrine column type for any
 * `Field::new()` call on a REAL mapped property (`FieldFactory::replaceGenericFieldsWithSpecificFields`
 * — a `string` enum column becomes `TextField`, a `json` column becomes `ArrayField`) and that
 * guess silently DISCARDS `formatValue()` (only the template path survives the swap), so a
 * formatValue-based rendering on a real property name is dead code: `TextField` also throws
 * outright for a raw array value, and `ArrayField` mis-renders one via a plain `implode()`. Naming
 * the field something the entity has no getter for skips the guess entirely (EasyAdmin's own "this
 * is a virtual field, so we can't autoconfigure it" branch) — at the cost of needing
 * `admin/field/raw_html.html.twig` set explicitly too, since an inaccessible/virtual field would
 * otherwise render an "Inaccessible" badge before `formatValue()` ever runs.
 *
 * @extends AbstractAdminCrudController<PlaylistGenerationJob>
 */
final class PlaylistGenerationJobCrudController extends AbstractAdminCrudController
{
    private const string RAW_HTML_TEMPLATE = 'admin/field/raw_html.html.twig';

    public function __construct(
        private readonly PlaylistRepository $playlistRepository,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PlaylistGenerationJob::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Playlist generation job')
            ->setEntityLabelInPlural('Playlist generation jobs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('state')->setChoices(self::enumChoices(JobState::cases())))
            ->add(TextFilter::new('providerKey', 'Provider'))
            ->add(ChoiceFilter::new('mode')->setChoices(self::enumChoices(JobMode::cases())))
            ->add(ChoiceFilter::new('blockedReason')->setChoices(self::enumChoices(BlockedReason::cases())))
            ->add(ChoiceFilter::new('failureReason')->setChoices(self::enumChoices(FailureReason::cases())));
    }

    /** @return iterable<FieldInterface> */
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Created');
        yield MaskedEmailField::new('owner.email', 'User');
        yield AssociationField::new('concert')->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => \sprintf('Concert #%d (%s)', (int) $job->getConcert()->getId(), $job->getConcert()->getDate()->format('Y-m-d')),
        );
        yield TextField::new('providerKey', 'Provider');
        yield Field::new('modeLabel', 'Mode')->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => $job->getMode()->value,
        );
        yield Field::new('stateLabel', 'State')->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => $job->getState()->value,
        );
        yield IntegerField::new('durationMs', 'Duration (ms)');
        yield IntegerField::new('matchedCount', 'Matched');
        yield IntegerField::new('songsTotal', 'Total songs');
        yield IntegerField::new('algorithmVersion', 'Algorithm version');

        // Detail-only: block/failure detail, stage timings, and the per-song track outcomes.
        yield Field::new('blockedReasonLabel', 'Blocked reason')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => $job->getBlockedReason() instanceof BlockedReason ? $job->getBlockedReason()->value : '—',
        );
        yield DateTimeField::new('resumableAfter', 'Resumable after')->onlyOnDetail();
        yield Field::new('blockedAtStageLabel', 'Blocked at stage')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => $job->getBlockedAtStage() instanceof PipelineStage ? $job->getBlockedAtStage()->value : '—',
        );
        yield IntegerField::new('blockCycleCount', 'Block cycle count')->onlyOnDetail();
        yield IntegerField::new('blockedMs', 'Blocked (ms)')->onlyOnDetail();
        yield Field::new('failureReasonLabel', 'Failure reason')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => $job->getFailureReason() instanceof FailureReason ? $job->getFailureReason()->value : '—',
        );
        yield Field::new('failureDetailPretty', 'Failure detail')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => self::prettyJson($job->getFailureDetail()),
        );
        yield IntegerField::new('lowConfidenceCount', 'Low-confidence matches')->onlyOnDetail();
        yield IntegerField::new('notFoundCount', 'Not found')->onlyOnDetail();
        yield IntegerField::new('skippedCount', 'Skipped')->onlyOnDetail();
        yield IntegerField::new('regionRestrictedCount', 'Region-restricted')->onlyOnDetail();
        yield Field::new('meanConfidenceLabel', 'Mean confidence')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => null !== $job->getMeanConfidence() ? htmlspecialchars((string) round($job->getMeanConfidence(), 3), \ENT_QUOTES) : '—',
        );
        yield DateTimeField::new('startedAt', 'Started at')->onlyOnDetail();
        yield DateTimeField::new('finishedAt', 'Finished at')->onlyOnDetail();
        yield Field::new('stageTimingsPretty', 'Stage timings')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            static fn (mixed $v, PlaylistGenerationJob $job): string => self::prettyJson($job->getStageTimings()),
        );
        yield Field::new('tracksTable', 'Playlist tracks')->onlyOnDetail()->setTemplatePath(self::RAW_HTML_TEMPLATE)->formatValue(
            fn (mixed $v, PlaylistGenerationJob $job): string => $this->renderTracksTable($job),
        );
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, \BackedEnum>
     */
    private static function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[(string) $case->value] = $case;
        }

        return $choices;
    }

    /** @param array<string, mixed>|null $data */
    private static function prettyJson(?array $data): string
    {
        if (null === $data || [] === $data) {
            return '<em>—</em>';
        }

        $json = json_encode($data, \JSON_PRETTY_PRINT) ?: '';

        return \sprintf('<pre>%s</pre>', htmlspecialchars($json, \ENT_QUOTES));
    }

    private function renderTracksTable(PlaylistGenerationJob $job): string
    {
        $playlist = $this->playlistRepository->findOneBy(['job' => $job]);
        if (!$playlist instanceof Playlist) {
            return '<em>No playlist has been generated for this job yet.</em>';
        }

        $rows = '';
        /** @var PlaylistTrack $track */
        foreach ($playlist->getTracks() as $track) {
            $reasonCode = $track->getReasonCode();
            $rows .= \sprintf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $track->getOrdinal(),
                htmlspecialchars($track->getSourceBand()->getName(), \ENT_QUOTES),
                htmlspecialchars($track->getSourceTitle(), \ENT_QUOTES),
                htmlspecialchars($track->getOutcome()->value, \ENT_QUOTES),
                null !== $track->getConfidence() ? htmlspecialchars((string) round($track->getConfidence(), 3), \ENT_QUOTES) : '—',
                htmlspecialchars($reasonCode instanceof ReportCode ? $reasonCode->value : '—', \ENT_QUOTES),
            );
        }

        if ('' === $rows) {
            return '<em>This playlist has no track rows.</em>';
        }

        return '<table class="table"><thead><tr>'
            .'<th>#</th><th>Band</th><th>Title</th><th>Outcome</th><th>Confidence</th><th>Reason code</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }
}
