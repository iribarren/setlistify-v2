<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Instant setlist refresh (docs/specs/2026-08-27-instant-setlist-refresh.md, D-257): entitlement is
 * a nullable grant timestamp on `users`, never a `roles` write (AC-7.1). The single column D-257
 * specifies — no provenance column on `bands` (AC-8.9), no new table.
 */
final class Version20260827100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Instant setlist refresh: users.instant_refresh_granted_at (nullable timestamptz).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD instant_refresh_granted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN instant_refresh_granted_at');
    }
}
