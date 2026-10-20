<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020153941 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hand-overs: a conversation forwarded with a note and a due date, waited on until the delegate replies';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hand_over (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, subject VARCHAR(998) NOT NULL, delegate_address VARCHAR(320) NOT NULL, delegate_name VARCHAR(255) DEFAULT NULL, note TEXT NOT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, state VARCHAR(16) NOT NULL, forward_message_id VARCHAR(998) DEFAULT NULL, overdue_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notice_round INT DEFAULT 0 NOT NULL, notify_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notified_channels JSON NOT NULL, notified_silently BOOLEAN NOT NULL, nudged_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, mail_account_id UUID NOT NULL, message_id UUID DEFAULT NULL, thread_id UUID DEFAULT NULL, forward_id UUID DEFAULT NULL, last_nudge_id UUID DEFAULT NULL, reply_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7AE4C99C7E3C61F9475BB34E ON hand_over (owner_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_7AE4C99C4FA8B546A393D2FB5303022F ON hand_over (mail_account_id, state, due_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7AE4C99C16ED395A ON hand_over (forward_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99C7E3C61F9 ON hand_over (owner_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99C4FA8B546 ON hand_over (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99C537A1329 ON hand_over (message_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99CE2904019 ON hand_over (thread_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99C4BFD2AF0 ON hand_over (last_nudge_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99C8A0E4E7F ON hand_over (reply_id)');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99C4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99C537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99CE2904019 FOREIGN KEY (thread_id) REFERENCES thread (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99C16ED395A FOREIGN KEY (forward_id) REFERENCES outgoing_message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99C4BFD2AF0 FOREIGN KEY (last_nudge_id) REFERENCES outgoing_message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99C8A0E4E7F FOREIGN KEY (reply_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99C7E3C61F9');
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99C4FA8B546');
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99C537A1329');
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99CE2904019');
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99C16ED395A');
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99C4BFD2AF0');
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99C8A0E4E7F');
        $this->addSql('DROP TABLE hand_over');
    }
}
