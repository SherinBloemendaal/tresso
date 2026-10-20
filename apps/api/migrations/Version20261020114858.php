<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020114858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bulk actions on a scope with their undo, and indexes for per-sender and size lookups';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bulk_action (id UUID NOT NULL, action VARCHAR(16) NOT NULL, area VARCHAR(16) NOT NULL, sender VARCHAR(320) DEFAULT NULL, last_message_before TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, larger_than BIGINT DEFAULT NULL, status VARCHAR(16) NOT NULL, resume_after UUID DEFAULT NULL, conversations INT NOT NULL, changed INT NOT NULL, unrestorable INT NOT NULL, restored INT NOT NULL, error VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, target_folder_id UUID DEFAULT NULL, smart_inbox_id UUID DEFAULT NULL, mail_account_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_671AAAFB7E3C61F98B8E8428 ON bulk_action (owner_id, created_at)');
        $this->addSql('CREATE INDEX IDX_671AAAFB7E3C61F9 ON bulk_action (owner_id)');
        $this->addSql('CREATE INDEX IDX_671AAAFB2298CB6E ON bulk_action (target_folder_id)');
        $this->addSql('CREATE INDEX IDX_671AAAFB346263DD ON bulk_action (smart_inbox_id)');
        $this->addSql('CREATE INDEX IDX_671AAAFB4FA8B546 ON bulk_action (mail_account_id)');
        $this->addSql('CREATE TABLE bulk_action_move (id UUID NOT NULL, bulk_action_id UUID NOT NULL, message_id UUID NOT NULL, moved_to_id UUID NOT NULL, moved_from_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_94240488765AF47FBF396750 ON bulk_action_move (bulk_action_id, id)');
        $this->addSql('CREATE INDEX IDX_94240488765AF47F ON bulk_action_move (bulk_action_id)');
        $this->addSql('CREATE INDEX IDX_94240488537A1329 ON bulk_action_move (message_id)');
        $this->addSql('CREATE INDEX IDX_94240488BFAF4E02 ON bulk_action_move (moved_to_id)');
        $this->addSql('CREATE INDEX IDX_94240488551F6FE8 ON bulk_action_move (moved_from_id)');
        $this->addSql('ALTER TABLE bulk_action ADD CONSTRAINT FK_671AAAFB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action ADD CONSTRAINT FK_671AAAFB2298CB6E FOREIGN KEY (target_folder_id) REFERENCES folder (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action ADD CONSTRAINT FK_671AAAFB346263DD FOREIGN KEY (smart_inbox_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action ADD CONSTRAINT FK_671AAAFB4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action_move ADD CONSTRAINT FK_94240488765AF47F FOREIGN KEY (bulk_action_id) REFERENCES bulk_action (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action_move ADD CONSTRAINT FK_94240488537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action_move ADD CONSTRAINT FK_94240488BFAF4E02 FOREIGN KEY (moved_to_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bulk_action_move ADD CONSTRAINT FK_94240488551F6FE8 FOREIGN KEY (moved_from_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B6BD307F4FA8B5468B108C2E6D4F7F99 ON message (mail_account_id, from_address, received_at)');
        $this->addSql('CREATE INDEX IDX_B6BD307F4FA8B546F7C0246A ON message (mail_account_id, size)');
        $this->addSql('CREATE INDEX IDX_31204C8312469DE26F60E3AF ON thread (category_id, last_message_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bulk_action DROP CONSTRAINT FK_671AAAFB7E3C61F9');
        $this->addSql('ALTER TABLE bulk_action DROP CONSTRAINT FK_671AAAFB2298CB6E');
        $this->addSql('ALTER TABLE bulk_action DROP CONSTRAINT FK_671AAAFB346263DD');
        $this->addSql('ALTER TABLE bulk_action DROP CONSTRAINT FK_671AAAFB4FA8B546');
        $this->addSql('ALTER TABLE bulk_action_move DROP CONSTRAINT FK_94240488765AF47F');
        $this->addSql('ALTER TABLE bulk_action_move DROP CONSTRAINT FK_94240488537A1329');
        $this->addSql('ALTER TABLE bulk_action_move DROP CONSTRAINT FK_94240488BFAF4E02');
        $this->addSql('ALTER TABLE bulk_action_move DROP CONSTRAINT FK_94240488551F6FE8');
        $this->addSql('DROP TABLE bulk_action');
        $this->addSql('DROP TABLE bulk_action_move');
        $this->addSql('DROP INDEX IDX_B6BD307F4FA8B5468B108C2E6D4F7F99');
        $this->addSql('DROP INDEX IDX_B6BD307F4FA8B546F7C0246A');
        $this->addSql('DROP INDEX IDX_31204C8312469DE26F60E3AF');
    }
}
