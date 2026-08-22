<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * setlist.fm integration (docs/specs/2026-08-22-setlistfm-integration.md): the durable cache tier
 * (`setlist_cache`), the queryable projection (`setlists`, `songs`), and identity-resolution
 * columns on `bands` (D-56). The partial unique index on `bands.setlistfm_mbid` is what makes a
 * two-rows-same-MBID collision a loud, detectable failure rather than a silent duplicate (AC-1.5).
 */
final class Version20260822130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'setlist.fm integration: setlist_cache, setlists, songs, band identity-resolution columns (D-56, D-59, D-60).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE setlist_cache (
                id SERIAL NOT NULL,
                cache_key VARCHAR(255) NOT NULL,
                endpoint VARCHAR(32) NOT NULL,
                payload JSON NOT NULL,
                fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                stale_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                http_status SMALLINT NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_setlist_cache_key ON setlist_cache (cache_key)');
        $this->addSql('CREATE INDEX idx_setlist_cache_endpoint ON setlist_cache (endpoint)');

        $this->addSql(<<<'SQL'
            CREATE TABLE setlists (
                id SERIAL NOT NULL,
                band_id INT NOT NULL,
                setlistfm_id VARCHAR(32) NOT NULL,
                event_date DATE NOT NULL,
                venue_name VARCHAR(200) DEFAULT NULL,
                venue_city VARCHAR(200) DEFAULT NULL,
                venue_country VARCHAR(2) DEFAULT NULL,
                tour_name VARCHAR(200) DEFAULT NULL,
                song_count SMALLINT NOT NULL,
                is_empty BOOLEAN NOT NULL,
                fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_setlists_setlistfm_id ON setlists (setlistfm_id)');
        $this->addSql('CREATE INDEX idx_setlists_band_event_date ON setlists (band_id, event_date)');
        $this->addSql('ALTER TABLE setlists ADD CONSTRAINT fk_setlists_band FOREIGN KEY (band_id) REFERENCES bands (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE songs (
                id SERIAL NOT NULL,
                setlist_id INT NOT NULL,
                position SMALLINT NOT NULL,
                set_label VARCHAR(40) DEFAULT NULL,
                title VARCHAR(200) NOT NULL,
                cover_of_name VARCHAR(200) DEFAULT NULL,
                cover_of_mbid VARCHAR(64) DEFAULT NULL,
                with_name VARCHAR(200) DEFAULT NULL,
                info TEXT DEFAULT NULL,
                is_tape BOOLEAN NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_songs_setlist_position ON songs (setlist_id, position)');
        $this->addSql('ALTER TABLE songs ADD CONSTRAINT fk_songs_setlist FOREIGN KEY (setlist_id) REFERENCES setlists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE bands ADD setlistfm_name VARCHAR(200) DEFAULT NULL');
        $this->addSql("ALTER TABLE bands ADD setlistfm_resolution_state VARCHAR(20) DEFAULT 'unresolved' NOT NULL");
        $this->addSql('ALTER TABLE bands ADD setlistfm_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE bands ADD setlistfm_resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // AC-1.5: two Band rows may never share a non-null MBID. Partial (WHERE NOT NULL) so an
        // unresolved band's NULL never collides with another unresolved band's NULL.
        $this->addSql('CREATE UNIQUE INDEX uniq_bands_setlistfm_mbid ON bands (setlistfm_mbid) WHERE setlistfm_mbid IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_bands_setlistfm_mbid');
        $this->addSql('ALTER TABLE bands DROP setlistfm_name');
        $this->addSql('ALTER TABLE bands DROP setlistfm_resolution_state');
        $this->addSql('ALTER TABLE bands DROP setlistfm_checked_at');
        $this->addSql('ALTER TABLE bands DROP setlistfm_resolved_at');

        $this->addSql('DROP TABLE songs');
        $this->addSql('DROP TABLE setlists');
        $this->addSql('DROP TABLE setlist_cache');
    }
}
