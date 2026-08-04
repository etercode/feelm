<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A place for the handful of values an administrator changes without a deploy.
 *
 * Created for the Telegram notifications — where they go, and which of them to
 * send — because the alternative was an environment variable, and an
 * environment variable cannot be edited from the admin, which is the thing that
 * was asked for.
 *
 * The name is the key. No surrogate id, because there is nothing else a row is
 * identified by and an id would only ever be found by the name anyway.
 */
final class Version20260804180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a settings table for admin-editable configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS settings (
            name VARCHAR(64) NOT NULL,
            value TEXT DEFAULT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(name)
        )');

        $this->addSql("COMMENT ON COLUMN settings.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS settings');
    }
}
