<?php

declare(strict_types=1);

namespace App\Service\Concert;

use App\Entity\Band;
use App\Repository\BandRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Band dedup (US-8, D-25): one `Band` row per real band across every user. `normalize()` is a pure
 * function reused by both dedup (this class) and search (`App\ApiResource\Filter\BandNameFilter`),
 * so the two can never drift apart (AC-4.2).
 *
 * Normalization deliberately prefers false merges over false splits (D-25) — see the class-level
 * rationale in the spec. It is NOT inlined into a database function/query so that prompt 09 can
 * replace the rule and re-derive `normalizedName` in a migration without touching any query.
 */
final readonly class BandResolver
{
    private const array LEADING_ARTICLES = ['the', 'los', 'las', 'el', 'la'];

    public function __construct(
        private BandRepository $bandRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * trim → collapse whitespace → Unicode NFKD → strip combining marks → lowercase → strip a
     * leading definite article → remove characters that are neither letters/digits nor whitespace.
     * `The Beatles` → `beatles`, `Sigur Rós` → `sigur ros`, `AC/DC` → `acdc`.
     */
    public static function normalize(string $name): string
    {
        $value = trim($name);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_KD);
        if (false !== $decomposed) {
            $value = $decomposed;
        }
        $value = (string) preg_replace('/\p{Mn}/u', '', $value);

        $value = mb_strtolower($value, 'UTF-8');

        foreach (self::LEADING_ARTICLES as $article) {
            $prefix = $article.' ';
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, \strlen($prefix));
                break;
            }
        }

        $value = (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Race-safe get-or-create (AC-8.5): the unique index on `bands.normalized_name` is the arbiter.
     * If two concurrent resolutions of a genuinely new band collide, the loser's insert fails with a
     * unique-constraint violation, which is caught and turned into a re-read — never a 500. The
     * insert attempt runs inside its own savepoint so a collision does not poison a wider
     * transaction the caller may be running (e.g. `App\State\Processor\ConcertCreateProcessor`'s
     * single-transaction guarantee, AC-1.8).
     *
     * `$rawName` must already be validated as non-blank and, after `normalize()`, non-empty — see
     * `App\Validator\ValidConcertInputValidator` (AC-9.4).
     */
    public function resolve(string $rawName): Band
    {
        $normalized = self::normalize($rawName);
        \assert('' !== $normalized, 'BandResolver::resolve() requires a name that normalizes to a non-empty string.');

        $existing = $this->bandRepository->findOneByNormalizedName($normalized);
        if (null !== $existing) {
            return $existing;
        }

        $em = $this->entityManager;
        $connection = $em->getConnection();
        // A nested beginTransaction() always uses a savepoint in this DBAL version — the setter
        // that used to make that opt-in is deprecated with no replacement (doctrine/dbal#5383)
        // because true is now the only supported behaviour.
        $connection->beginTransaction();

        $band = new Band(trim($rawName), $normalized, $this->clock->now());

        try {
            $em->persist($band);
            $em->flush();
            $connection->commit();

            return $band;
        } catch (UniqueConstraintViolationException) {
            $connection->rollBack();
            $em->detach($band);

            $existing = $this->bandRepository->findOneByNormalizedName($normalized);

            return $existing ?? throw new \LogicException('Band resolution race could not be resolved: no row found after a unique-constraint violation.');
        }
    }
}
