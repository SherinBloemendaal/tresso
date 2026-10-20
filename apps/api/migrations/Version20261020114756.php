<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020114756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Batched delivery: held and released mail, batch schedules; one-click unsubscribe requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE batch_schedule (times JSON NOT NULL, time_zone VARCHAR(64) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE TABLE unsubscription (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, sender VARCHAR(320) NOT NULL, method VARCHAR(16) NOT NULL, uri VARCHAR(2048) NOT NULL, status VARCHAR(16) NOT NULL, error VARCHAR(500) DEFAULT NULL, attempts INT NOT NULL, not_before TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, claimed_by VARCHAR(128) DEFAULT NULL, claimed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mail_account_id UUID NOT NULL, message_id UUID DEFAULT NULL, outgoing_message_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9371D0BD4FA8B546475BB34E ON unsubscription (mail_account_id, change_seq)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9371D0BD4FA8B5465F004ACF ON unsubscription (mail_account_id, sender)');
        $this->addSql('CREATE INDEX IDX_9371D0BD4FA8B546 ON unsubscription (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_9371D0BD537A1329 ON unsubscription (message_id)');
        $this->addSql('CREATE INDEX IDX_9371D0BDCBD95BBF ON unsubscription (outgoing_message_id)');
        $this->addSql('ALTER TABLE batch_schedule ADD CONSTRAINT FK_D1D67F9BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unsubscription ADD CONSTRAINT FK_9371D0BD4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unsubscription ADD CONSTRAINT FK_9371D0BD537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unsubscription ADD CONSTRAINT FK_9371D0BDCBD95BBF FOREIGN KEY (outgoing_message_id) REFERENCES outgoing_message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message ADD held_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD batched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX message_held ON message (held_until) WHERE (held_until IS NOT NULL)');
        $this->addSql('ALTER TABLE thread ADD held_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE thread ADD batched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX thread_held ON thread (held_until) WHERE (held_until IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE batch_schedule DROP CONSTRAINT FK_D1D67F9BA76ED395');
        $this->addSql('ALTER TABLE unsubscription DROP CONSTRAINT FK_9371D0BD4FA8B546');
        $this->addSql('ALTER TABLE unsubscription DROP CONSTRAINT FK_9371D0BD537A1329');
        $this->addSql('ALTER TABLE unsubscription DROP CONSTRAINT FK_9371D0BDCBD95BBF');
        $this->addSql('DROP TABLE batch_schedule');
        $this->addSql('DROP TABLE unsubscription');
        $this->addSql('DROP INDEX message_held');
        $this->addSql('ALTER TABLE message DROP held_until');
        $this->addSql('ALTER TABLE message DROP batched_at');
        $this->addSql('DROP INDEX thread_held');
        $this->addSql('ALTER TABLE thread DROP held_until');
        $this->addSql('ALTER TABLE thread DROP batched_at');
    }
}
