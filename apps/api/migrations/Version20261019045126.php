<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019045126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Queue the changes users make to messages (flags, moves) for the sync worker to make on the mail server';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mailbox_change (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, seen BOOLEAN DEFAULT NULL, flagged BOOLEAN DEFAULT NULL, source_remote_uid VARCHAR(255) DEFAULT NULL, source_mod_seq BIGINT DEFAULT NULL, source_uid_validity BIGINT DEFAULT NULL, attempts INT NOT NULL, not_before TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, claimed_by VARCHAR(120) DEFAULT NULL, claimed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, mail_account_id UUID NOT NULL, message_id UUID NOT NULL, source_folder_id UUID DEFAULT NULL, target_folder_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DBB901C84FA8B546 ON mailbox_change (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_DBB901C8537A1329 ON mailbox_change (message_id)');
        $this->addSql('CREATE INDEX IDX_DBB901C87EB22A8C ON mailbox_change (source_folder_id)');
        $this->addSql('CREATE INDEX IDX_DBB901C82298CB6E ON mailbox_change (target_folder_id)');
        $this->addSql('ALTER TABLE mailbox_change ADD CONSTRAINT FK_DBB901C84FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_change ADD CONSTRAINT FK_DBB901C8537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_change ADD CONSTRAINT FK_DBB901C87EB22A8C FOREIGN KEY (source_folder_id) REFERENCES folder (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_change ADD CONSTRAINT FK_DBB901C82298CB6E FOREIGN KEY (target_folder_id) REFERENCES folder (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mailbox_change DROP CONSTRAINT FK_DBB901C84FA8B546');
        $this->addSql('ALTER TABLE mailbox_change DROP CONSTRAINT FK_DBB901C8537A1329');
        $this->addSql('ALTER TABLE mailbox_change DROP CONSTRAINT FK_DBB901C87EB22A8C');
        $this->addSql('ALTER TABLE mailbox_change DROP CONSTRAINT FK_DBB901C82298CB6E');
        $this->addSql('DROP TABLE mailbox_change');
    }
}
