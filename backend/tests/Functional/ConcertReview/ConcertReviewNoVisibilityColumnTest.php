<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D-238, AC-4.5: there is no `isPublic`, `visibility` or `sharedAt` column on `ConcertReview` — a
 * schema introspection test, so prompt 21 has to make sharing an explicit decision rather than
 * finding a flag already flipped.
 */
final class ConcertReviewNoVisibilityColumnTest extends KernelTestCase
{
    public function testTheRealDatabaseColumnListHasNoVisibilityShapedColumn(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $columns = $connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'concert_reviews'",
        );

        self::assertNotEmpty($columns, 'concert_reviews must exist for this test to mean anything.');

        $forbidden = ['visibility', 'is_public', 'shared_at', 'ispublic', 'sharedat'];
        foreach ($columns as $column) {
            self::assertIsString($column);
            self::assertNotContains(
                strtolower($column),
                $forbidden,
                \sprintf('concert_reviews.%s looks visibility-shaped — D-238 says no such column ships in this branch.', $column),
            );
        }
    }

    public function testTheDoctrineMappingHasNoVisibilityShapedField(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getClassMetadata(\App\Entity\ConcertReview::class);

        $forbidden = ['visibility', 'isPublic', 'sharedAt'];
        foreach ($forbidden as $field) {
            self::assertFalse(
                $metadata->hasField($field) || $metadata->hasAssociation($field),
                \sprintf('ConcertReview must not map a "%s" field (D-238).', $field),
            );
        }
    }
}
