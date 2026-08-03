<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where an account reads the site, and what clock it keeps.
 *
 * Both are NOT NULL with a default rather than nullable. A null language would
 * mean "ask again later" and every read path would have to carry a fallback;
 * the fallback is the same for everyone, so it belongs in the column. Existing
 * rows take English and UTC, which is exactly what they were being rendered in
 * before this migration existed.
 */
final class Version20260803150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locale and timezone display preferences to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users
            ADD locale VARCHAR(5) DEFAULT 'en' NOT NULL,
            ADD timezone VARCHAR(64) DEFAULT 'UTC' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP locale, DROP timezone');
    }
}
