<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline migration for the backend skeleton (docs/specs/2026-08-21-backend-skeleton.md).
 *
 * This feature defines no domain entity (D-4, AC-4.6) — `Entity/` is deliberately empty, filled
 * by prompt 05 onward. There is therefore no schema to create yet; this migration exists so that
 * `doctrine:migrations:migrate` has something to run and `migrations/` is not empty, establishing
 * the migration-only workflow (AC-4.4) before any real schema change needs it.
 */
final class Version20260821011117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: no schema yet — see backend/migrations README and D-4.';
    }

    public function up(Schema $schema): void
    {
        // Intentionally empty. See class docblock.
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty. See class docblock.
    }
}
