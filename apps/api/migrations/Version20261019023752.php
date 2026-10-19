<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019023752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outgoing messages: the outbox the sync workers send from';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE outgoing_message (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, status VARCHAR(16) NOT NULL, send_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_id_header VARCHAR(998) NOT NULL, subject VARCHAR(998) NOT NULL, recipients JSON NOT NULL, raw_object_key VARCHAR(255) NOT NULL, size INT NOT NULL, attempts INT NOT NULL, last_error VARCHAR(500) DEFAULT NULL, claimed_by VARCHAR(120) DEFAULT NULL, claimed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, mail_account_id UUID NOT NULL, in_reply_to_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CF2CA8964FA8B5467B00651CC6FDA417 ON outgoing_message (mail_account_id, status, send_at)');
        $this->addSql('CREATE INDEX IDX_CF2CA8964FA8B546475BB34E ON outgoing_message (mail_account_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_CF2CA8964FA8B546 ON outgoing_message (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_CF2CA896DD92DAB8 ON outgoing_message (in_reply_to_id)');
        $this->addSql('ALTER TABLE outgoing_message ADD CONSTRAINT FK_CF2CA8964FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE outgoing_message ADD CONSTRAINT FK_CF2CA896DD92DAB8 FOREIGN KEY (in_reply_to_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE outgoing_message DROP CONSTRAINT FK_CF2CA8964FA8B546');
        $this->addSql('ALTER TABLE outgoing_message DROP CONSTRAINT FK_CF2CA896DD92DAB8');
        $this->addSql('DROP TABLE outgoing_message');
    }
}
