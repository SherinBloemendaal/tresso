<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019144341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Files attached to drafts, and drafts kept while their message is being sent';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE draft_attachment (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, content_type VARCHAR(255) NOT NULL, size BIGINT NOT NULL, sha256 VARCHAR(64) NOT NULL, object_key VARCHAR(255) NOT NULL, scan_status VARCHAR(16) DEFAULT \'pending\' NOT NULL, scan_signature VARCHAR(255) DEFAULT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, draft_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_460AA8104DDB172C ON draft_attachment (object_key)');
        $this->addSql('CREATE INDEX IDX_460AA810E2F3C5D1 ON draft_attachment (draft_id)');
        $this->addSql('ALTER TABLE draft_attachment ADD CONSTRAINT FK_460AA810E2F3C5D1 FOREIGN KEY (draft_id) REFERENCES draft (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD sending_as_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694A0A55476 FOREIGN KEY (sending_as_id) REFERENCES outgoing_message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_467C9694A0A55476 ON draft (sending_as_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE draft_attachment DROP CONSTRAINT FK_460AA810E2F3C5D1');
        $this->addSql('DROP TABLE draft_attachment');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694A0A55476');
        $this->addSql('DROP INDEX IDX_467C9694A0A55476');
        $this->addSql('ALTER TABLE draft DROP sending_as_id');
    }
}
