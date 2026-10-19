<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019032532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drafts synced across devices, and copies to remove from Drafts folders';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE draft (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, kind VARCHAR(16) NOT NULL, recipients JSON NOT NULL, subject VARCHAR(998) NOT NULL, html TEXT NOT NULL, quote_html TEXT NOT NULL, version INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, filed_version INT NOT NULL, filed_message_id VARCHAR(998) DEFAULT NULL, filing_claimed_by VARCHAR(120) DEFAULT NULL, filing_claimed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, mail_account_id UUID DEFAULT NULL, signature_id UUID DEFAULT NULL, in_reply_to_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_467C96947E3C61F9475BB34E ON draft (owner_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_467C96944FA8B546D660E32C ON draft (mail_account_id, filed_version)');
        $this->addSql('CREATE INDEX IDX_467C96947E3C61F9 ON draft (owner_id)');
        $this->addSql('CREATE INDEX IDX_467C96944FA8B546 ON draft (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_467C9694ED61183A ON draft (signature_id)');
        $this->addSql('CREATE INDEX IDX_467C9694DD92DAB8 ON draft (in_reply_to_id)');
        $this->addSql('CREATE TABLE draft_copy_removal (id UUID NOT NULL, message_id VARCHAR(998) NOT NULL, claimed_by VARCHAR(120) DEFAULT NULL, claimed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mail_account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E23C78FD4FA8B546 ON draft_copy_removal (mail_account_id)');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C96947E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C96944FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694ED61183A FOREIGN KEY (signature_id) REFERENCES signature (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694DD92DAB8 FOREIGN KEY (in_reply_to_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft_copy_removal ADD CONSTRAINT FK_E23C78FD4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C96947E3C61F9');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C96944FA8B546');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694ED61183A');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694DD92DAB8');
        $this->addSql('ALTER TABLE draft_copy_removal DROP CONSTRAINT FK_E23C78FD4FA8B546');
        $this->addSql('DROP TABLE draft');
        $this->addSql('DROP TABLE draft_copy_removal');
    }
}
