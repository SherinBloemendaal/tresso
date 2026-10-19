<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019012542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change tracking: per-user change numbers and tombstones for client sync';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sync_tombstone (id UUID NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_id UUID NOT NULL, change_seq BIGINT NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EE455A52A76ED395475BB34E ON sync_tombstone (user_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_EE455A52A76ED395 ON sync_tombstone (user_id)');
        $this->addSql('ALTER TABLE sync_tombstone ADD CONSTRAINT FK_EE455A52A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE folder ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_ECA209CD4FA8B546475BB34E ON folder (mail_account_id, change_seq)');
        $this->addSql('ALTER TABLE mail_account ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_A78BD7CB7E3C61F9475BB34E ON mail_account (owner_id, change_seq)');
        $this->addSql('ALTER TABLE message ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_B6BD307F4FA8B546475BB34E ON message (mail_account_id, change_seq)');
        $this->addSql('ALTER TABLE thread ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_31204C834FA8B546475BB34E ON thread (mail_account_id, change_seq)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sync_tombstone DROP CONSTRAINT FK_EE455A52A76ED395');
        $this->addSql('DROP TABLE sync_tombstone');
        $this->addSql('ALTER TABLE app_user DROP change_seq');
        $this->addSql('DROP INDEX IDX_ECA209CD4FA8B546475BB34E');
        $this->addSql('ALTER TABLE folder DROP change_seq');
        $this->addSql('DROP INDEX IDX_A78BD7CB7E3C61F9475BB34E');
        $this->addSql('ALTER TABLE mail_account DROP change_seq');
        $this->addSql('DROP INDEX IDX_B6BD307F4FA8B546475BB34E');
        $this->addSql('ALTER TABLE message DROP change_seq');
        $this->addSql('DROP INDEX IDX_31204C834FA8B546475BB34E');
        $this->addSql('ALTER TABLE thread DROP change_seq');
    }
}
