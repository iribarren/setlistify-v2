<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\SerializerContextBuilderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\ConcertReviewInput;
use App\ApiResource\ConcertReviewOutput;
use App\Entity\Concert;
use App\Entity\ConcertReview;
use App\Entity\Song;
use App\Repository\ConcertReviewRepository;
use App\Repository\SongRepository;
use App\Security\ConcertReviewOwnerExtension;
use App\Security\Voter\ConcertVoter;
use App\State\ConcertLocator;
use App\State\ConcertReviewOutputMapper;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * `PUT /api/concerts/{concertId}/review` (D-228). The endpoint's shape IS the "second write edits
 * the first" guarantee (AC-3.2): there is no id to look up, so a write always either creates the
 * one row this owner/concert pair may have, or updates it.
 *
 * Two rules live here rather than on `ConcertReviewInput` because both need context the DTO alone
 * doesn't have: **D-234** (a first write on an `upcoming` concert is a 422 `REVIEW_NOT_YET`, never
 * on edit/delete — D-235) and **D-233** (a supplied `highlightSongId` must belong to a `Setlist` of
 * a band in *this* concert's lineup, else the field becomes an id-probing oracle).
 *
 * **AC-3.4** — the race-safe create is the same shape as `App\Service\Concert\BandResolver::resolve()`:
 * a nested transaction (a real savepoint in this DBAL version) around the insert attempt, a caught
 * `UniqueConstraintViolationException` on the concurrent-loser side, and a re-read that then falls
 * through to the update path — never a 500.
 *
 * **201 vs 200 (D-228)**: API Platform 4's write pipeline is a single synchronous processor chain
 * invoked once by `ApiPlatform\Symfony\Controller\MainController` — there is no per-listener
 * re-resolution of the `Operation` the way the older event-based pipeline had, so a processor can't
 * flip the *declared* operation's status by mutating request attributes past this point. Instead,
 * on a genuine create this processor serializes the response itself and returns a real `Response`
 * with `201` — `SerializeProcessor`/`RespondProcessor` both special-case `$data instanceof Response`
 * as a pass-through (the same guard API Platform's own processors use), so this is a supported
 * shortcut, not a workaround. An update returns the plain DTO and lets the normal chain serialize it
 * with the operation's default (`200`).
 *
 * @implements ProcessorInterface<ConcertReviewInput, ConcertReviewOutput|Response>
 */
final readonly class ConcertReviewPutProcessor implements ProcessorInterface
{
    public function __construct(
        private ConcertLocator $concertLocator,
        private ConcertReviewRepository $reviewRepository,
        private ConcertReviewOwnerExtension $ownerExtension,
        private SongRepository $songRepository,
        private ConcertReviewOutputMapper $mapper,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        #[Autowire(service: 'api_platform.serializer')]
        private SerializerInterface $serializer,
        private SerializerContextBuilderInterface $serializerContextBuilder,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConcertReviewOutput|Response
    {
        // $data is already narrowed to ConcertReviewInput here via @implements ProcessorInterface<ConcertReviewInput, ConcertReviewOutput|Response>.
        $concert = $this->concertLocator->locate($uriVariables['concertId'] ?? null, ConcertVoter::EDIT);
        $now = $this->clock->now();

        $highlightSong = $this->resolveHighlightSong($data->highlightSongId, $concert);

        $existing = $this->findExisting($concert);

        if (null !== $existing) {
            $existing->apply($data->rating, $data->notes, $highlightSong, $data->highlightTitle, $now);
            $this->entityManager->flush();

            return $this->mapper->map($existing);
        }

        // D-234: the past-only rule gates CREATION only — never edit (above) or delete.
        $this->assertConcertHasHappened($concert);

        [$review, $wasCreated] = $this->createRaceSafe($concert, $data, $highlightSong, $now);
        $output = $this->mapper->map($review);

        if (!$wasCreated) {
            // AC-3.4: this request lost the race — another one already created the row, so from
            // this request's own point of view it is an update, not a create (200, not 201).
            return $output;
        }

        /** @var Request $request */
        $request = $context['request'];

        return $this->respondCreated($output, $operation, $uriVariables, $request);
    }

    /**
     * See the class docblock's "201 vs 200" note for why this builds the Response by hand.
     *
     * @param array<string, mixed> $uriVariables
     */
    private function respondCreated(ConcertReviewOutput $output, Operation $operation, array $uriVariables, Request $request): Response
    {
        $serializerContext = $this->serializerContextBuilder->createFromRequest($request, true, [
            'resource_class' => $operation->getClass(),
            'operation' => $operation,
        ]);
        $serializerContext['uri_variables'] = $uriVariables;

        $format = $request->getRequestFormat() ?? 'jsonld';
        $serialized = $this->serializer->serialize($output, $format, $serializerContext);

        $mimeTypes = $request->getMimeTypes($format);

        return new Response($serialized, Response::HTTP_CREATED, [
            'Content-Type' => $mimeTypes[0] ?? 'application/ld+json',
        ]);
    }

    private function findExisting(Concert $concert): ?ConcertReview
    {
        $queryBuilder = $this->reviewRepository->createConcertReviewQueryBuilder('r');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), ConcertReview::class, []);
        $queryBuilder->andWhere('r.concert = :concert')->setParameter('concert', $concert);

        $review = $queryBuilder->getQuery()->getOneOrNullResult();

        return $review instanceof ConcertReview ? $review : null;
    }

    /**
     * AC-3.4: the insert attempt runs inside its own savepoint so a unique-constraint collision
     * doesn't poison a wider transaction; the loser re-reads the winner's row and updates it
     * instead — never a 500.
     *
     * A failed `flush()` leaves the `EntityManager` itself closed (not just the SQL transaction
     * rolled back) — this ORM version has no "recover in place" path, so the retry re-fetches a
     * FRESH manager from the registry rather than reusing `$this->entityManager` afterward.
     *
     * @return array{ConcertReview, bool} the row, and whether THIS call is the one that created it
     *                                    — false means it lost the race and landed as an update
     *                                    (D-228: still a 200, never a 201, from this request's own
     *                                    point of view).
     */
    private function createRaceSafe(Concert $concert, ConcertReviewInput $data, ?Song $highlightSong, \DateTimeImmutable $now): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        $review = new ConcertReview($concert->getOwner(), $concert, $now);
        $review->apply($data->rating, $data->notes, $highlightSong, $data->highlightTitle, $now);

        \assert($review->getOwner() === $concert->getOwner(), 'ConcertReview.owner must always equal ConcertReview.concert.owner (AC-3.5).');

        try {
            $this->entityManager->persist($review);
            $this->entityManager->flush();
            $connection->commit();

            return [$review, true];
        } catch (UniqueConstraintViolationException) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $freshEntityManager = $this->reopenEntityManager();

            $queryBuilder = $freshEntityManager->getRepository(ConcertReview::class)->createQueryBuilder('r');
            $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), ConcertReview::class, []);
            $queryBuilder->andWhere('r.concert = :concert')->setParameter('concert', $concert->getId());
            $existing = $queryBuilder->getQuery()->getOneOrNullResult();

            if (!$existing instanceof ConcertReview) {
                throw new \LogicException('Concert review creation race could not be resolved: no row found after a unique-constraint violation.');
            }

            $existing->apply($data->rating, $data->notes, $highlightSong, $data->highlightTitle, $now);
            $freshEntityManager->flush();

            return [$existing, false];
        }
    }

    /**
     * `Doctrine\ORM\EntityManagerInterface::flush()` closes the manager on any exception, this
     * ORM version included (`errorIfClosed()`), so the retry below needs a manager the registry
     * considers current, not the one already injected into this processor.
     */
    private function reopenEntityManager(): EntityManagerInterface
    {
        if (!$this->entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
        }

        /** @var EntityManagerInterface $manager */
        $manager = $this->managerRegistry->getManager();

        return $manager;
    }

    /** D-234: a first write on an `upcoming` concert is rejected — code `REVIEW_NOT_YET`, propertyPath "". */
    private function assertConcertHasHappened(Concert $concert): void
    {
        if ($concert->getPastAfter() <= $this->clock->now()) {
            return;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(
                message: 'You can write about this concert once it has happened.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: '',
                invalidValue: null,
                code: 'REVIEW_NOT_YET',
            ),
        ]));
    }

    /** D-233: a supplied highlight must belong to a Setlist of a band in THIS concert's lineup. */
    private function resolveHighlightSong(?int $highlightSongId, Concert $concert): ?Song
    {
        if (null === $highlightSongId) {
            return null;
        }

        $song = $this->songRepository->find($highlightSongId);

        if (null !== $song && $this->bandIsInLineup($song->getSetlist()->getBand()->getId(), $concert)) {
            return $song;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This song is not part of a setlist for a band in this concert\'s lineup.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'highlightSongId',
                invalidValue: $highlightSongId,
                code: 'HIGHLIGHT_OUT_OF_SCOPE',
            ),
        ]));
    }

    private function bandIsInLineup(?int $bandId, Concert $concert): bool
    {
        if (null === $bandId) {
            return false;
        }

        foreach ($concert->getConcertBands() as $concertBand) {
            if ($concertBand->getBand()->getId() === $bandId) {
                return true;
            }
        }

        return false;
    }
}
