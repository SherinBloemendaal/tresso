<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019153927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Draft attachments shown inside the text (images of a quoted original)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE draft_attachment ADD inline BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE draft_attachment ADD content_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE draft_attachment DROP inline');
        $this->addSql('ALTER TABLE draft_attachment DROP content_id');
    }
}
