<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019161813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Smart inboxes in the client sync, classification per conversation, sender rules and message signals';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message_signals (headers JSON NOT NULL, junk BOOLEAN NOT NULL, message_id UUID NOT NULL, PRIMARY KEY (message_id))');
        $this->addSql('CREATE TABLE sender_rule (id UUID NOT NULL, sender VARCHAR(320) NOT NULL, verdict VARCHAR(16) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, category_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B9325A1F7E3C61F95F004ACF ON sender_rule (owner_id, sender)');
        $this->addSql('CREATE INDEX IDX_B9325A1F7E3C61F9 ON sender_rule (owner_id)');
        $this->addSql('CREATE INDEX IDX_B9325A1F12469DE2 ON sender_rule (category_id)');
        $this->addSql('ALTER TABLE message_signals ADD CONSTRAINT FK_4625FF01537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sender_rule ADD CONSTRAINT FK_B9325A1F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sender_rule ADD CONSTRAINT FK_B9325A1F12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE category ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_64C19C17E3C61F9475BB34E ON category (owner_id, change_seq)');
        $this->addSql('CREATE INDEX message_unclassified ON message (mail_account_id, received_at) WHERE (classified_at IS NULL)');
        $this->addSql('ALTER TABLE thread ADD verdict VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE thread ADD classification_reason VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE thread ADD classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE thread ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE thread ADD CONSTRAINT FK_31204C8312469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_31204C8312469DE2 ON thread (category_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_signals DROP CONSTRAINT FK_4625FF01537A1329');
        $this->addSql('ALTER TABLE sender_rule DROP CONSTRAINT FK_B9325A1F7E3C61F9');
        $this->addSql('ALTER TABLE sender_rule DROP CONSTRAINT FK_B9325A1F12469DE2');
        $this->addSql('DROP TABLE message_signals');
        $this->addSql('DROP TABLE sender_rule');
        $this->addSql('DROP INDEX IDX_64C19C17E3C61F9475BB34E');
        $this->addSql('ALTER TABLE category DROP change_seq');
        $this->addSql('DROP INDEX message_unclassified');
        $this->addSql('ALTER TABLE thread DROP CONSTRAINT FK_31204C8312469DE2');
        $this->addSql('DROP INDEX IDX_31204C8312469DE2');
        $this->addSql('ALTER TABLE thread DROP verdict');
        $this->addSql('ALTER TABLE thread DROP classification_reason');
        $this->addSql('ALTER TABLE thread DROP classified_at');
        $this->addSql('ALTER TABLE thread DROP category_id');
    }
}
