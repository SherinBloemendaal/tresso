<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019025801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Search: message body text for the index and the per-user search cursor';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message_text (text TEXT NOT NULL, message_id UUID NOT NULL, PRIMARY KEY (message_id))');
        $this->addSql('CREATE TABLE search_cursor (indexed_seq BIGINT DEFAULT 0 NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE message_text ADD CONSTRAINT FK_921E3C1E537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE search_cursor ADD CONSTRAINT FK_1C9D0591A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_text DROP CONSTRAINT FK_921E3C1E537A1329');
        $this->addSql('ALTER TABLE search_cursor DROP CONSTRAINT FK_1C9D0591A76ED395');
        $this->addSql('DROP TABLE message_text');
        $this->addSql('DROP TABLE search_cursor');
    }
}
