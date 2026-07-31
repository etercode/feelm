<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere to keep an uploaded portrait.
 *
 * The column holds a public path under /media, not the image — the file lives
 * on the media volume nginx already serves. Null keeps the drawn initials,
 * which is what every existing account stays on.
 */
final class Version20260731120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.avatar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN avatar');
    }
}
