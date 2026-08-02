<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a hand-corrected rating survive the next import.
 *
 * Editing a rating in the admin is pointless without this. app:catalog:imdb-ratings
 * upserts every title it matches — ON CONFLICT DO UPDATE — so a correction made
 * by a person would live until the next run of the dataset import and then be
 * silently replaced by IMDb's number again.
 *
 * Locked rows are skipped by that import instead. The flag is on the rating and
 * not on the work, so locking IMDb's score does not also freeze TMDB's, and
 * unlocking is how you hand a title back to the dataset.
 */
final class Version20260802180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add work_ratings.locked so manual edits are not overwritten by the importer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_ratings ADD COLUMN locked BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_ratings DROP COLUMN locked');
    }
}
