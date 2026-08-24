<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824181855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Playlist result state gaps: nullable Setlist.url (D-186) — no backfill, existing rows stay null.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setlists ADD url TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setlists DROP url');
    }
}
