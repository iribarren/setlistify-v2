<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821170102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backoffice foundation: TOTP secret (encrypted) + hashed backup codes on users (AC-5.3, AC-5.4).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD totp_secret_cipher TEXT DEFAULT NULL');
        // DEFAULT so this stays safe to run against a table with existing rows (e.g. a user
        // created before this migration) — the application always writes a real array once 2FA
        // enrollment completes.
        $this->addSql("ALTER TABLE users ADD backup_codes_hashed JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP totp_secret_cipher');
        $this->addSql('ALTER TABLE users DROP backup_codes_hashed');
    }
}
