<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019032812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendars, calendar objects and their events, calendar invitations from mail, and calendar writes for the sync workers.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE calendar (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, source VARCHAR(16) NOT NULL, remote_id VARCHAR(2048) DEFAULT NULL, name VARCHAR(255) NOT NULL, color VARCHAR(7) DEFAULT NULL, user_color VARCHAR(7) DEFAULT NULL, time_zone VARCHAR(64) DEFAULT NULL, writable BOOLEAN NOT NULL, is_default BOOLEAN NOT NULL, visible BOOLEAN NOT NULL, provider_schedules BOOLEAN NOT NULL, position INT NOT NULL, sync_cursor TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, mail_account_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6EA9A1467E3C61F9475BB34E ON calendar (owner_id, change_seq)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6EA9A1464FA8B5462A3E9C94 ON calendar (mail_account_id, remote_id)');
        $this->addSql('CREATE INDEX IDX_6EA9A1467E3C61F9 ON calendar (owner_id)');
        $this->addSql('CREATE INDEX IDX_6EA9A1464FA8B546 ON calendar (mail_account_id)');
        $this->addSql('CREATE TABLE calendar_event (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, uid VARCHAR(1024) NOT NULL, recurrence_key VARCHAR(16) NOT NULL, sequence INT NOT NULL, status VARCHAR(16) NOT NULL, summary TEXT NOT NULL, description TEXT NOT NULL, location TEXT NOT NULL, url VARCHAR(2048) DEFAULT NULL, transparency VARCHAR(8) NOT NULL, visibility VARCHAR(16) NOT NULL, all_day BOOLEAN NOT NULL, start_value VARCHAR(19) NOT NULL, start_time_zone VARCHAR(64) DEFAULT NULL, end_value VARCHAR(19) NOT NULL, end_time_zone VARCHAR(64) DEFAULT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, series_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rrule TEXT DEFAULT NULL, rdates JSON NOT NULL, exdates JSON NOT NULL, organizer JSON DEFAULT NULL, attendees JSON NOT NULL, self_partstat VARCHAR(16) DEFAULT NULL, reminders JSON NOT NULL, conference JSON DEFAULT NULL, sync_error VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, calendar_id UUID NOT NULL, object_id UUID NOT NULL, message_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_57FA09C9A40A2C855A0507C ON calendar_event (calendar_id, starts_at)');
        $this->addSql('CREATE INDEX IDX_57FA09C9A40A2C87741B555 ON calendar_event (calendar_id, series_ends_at)');
        $this->addSql('CREATE INDEX IDX_57FA09C9A40A2C8475BB34E ON calendar_event (calendar_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_57FA09C9537A1329 ON calendar_event (message_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_57FA09C9A40A2C8539B06064FB9E83C ON calendar_event (calendar_id, uid, recurrence_key)');
        $this->addSql('CREATE INDEX IDX_57FA09C9A40A2C8 ON calendar_event (calendar_id)');
        $this->addSql('CREATE INDEX IDX_57FA09C9232D562B ON calendar_event (object_id)');
        $this->addSql('CREATE TABLE calendar_invitation (id UUID NOT NULL, method VARCHAR(16) NOT NULL, uid VARCHAR(1024) NOT NULL, sequence INT NOT NULL, recurrence_key VARCHAR(16) NOT NULL, ical TEXT NOT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6757A5A7537A1329 ON calendar_invitation (message_id)');
        $this->addSql('CREATE INDEX IDX_6757A5A7539B0606 ON calendar_invitation (uid)');
        $this->addSql('CREATE TABLE calendar_object (id UUID NOT NULL, uid VARCHAR(1024) NOT NULL, remote_id VARCHAR(2048) DEFAULT NULL, etag VARCHAR(512) DEFAULT NULL, ical TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2CBB3B0A40A2C82A3E9C94 ON calendar_object (calendar_id, remote_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2CBB3B0A40A2C8539B0606 ON calendar_object (calendar_id, uid)');
        $this->addSql('CREATE INDEX IDX_2CBB3B0A40A2C8 ON calendar_object (calendar_id)');
        $this->addSql('CREATE TABLE calendar_operation (id UUID NOT NULL, kind VARCHAR(8) NOT NULL, uid VARCHAR(1024) NOT NULL, remote_id VARCHAR(2048) DEFAULT NULL, if_match VARCHAR(512) DEFAULT NULL, claimed_by VARCHAR(120) DEFAULT NULL, claimed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, not_before TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, attempts INT NOT NULL, last_error VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, mail_account_id UUID NOT NULL, calendar_id UUID NOT NULL, object_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5AED2D274FA8B546D3B37FBF ON calendar_operation (mail_account_id, claimed_until)');
        $this->addSql('CREATE INDEX IDX_5AED2D274FA8B546 ON calendar_operation (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_5AED2D27A40A2C8 ON calendar_operation (calendar_id)');
        $this->addSql('CREATE INDEX IDX_5AED2D27232D562B ON calendar_operation (object_id)');
        $this->addSql('ALTER TABLE calendar ADD CONSTRAINT FK_6EA9A1467E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar ADD CONSTRAINT FK_6EA9A1464FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_57FA09C9A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_57FA09C9232D562B FOREIGN KEY (object_id) REFERENCES calendar_object (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_57FA09C9537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_invitation ADD CONSTRAINT FK_6757A5A7537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_object ADD CONSTRAINT FK_2CBB3B0A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_operation ADD CONSTRAINT FK_5AED2D274FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_operation ADD CONSTRAINT FK_5AED2D27A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_operation ADD CONSTRAINT FK_5AED2D27232D562B FOREIGN KEY (object_id) REFERENCES calendar_object (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar DROP CONSTRAINT FK_6EA9A1467E3C61F9');
        $this->addSql('ALTER TABLE calendar DROP CONSTRAINT FK_6EA9A1464FA8B546');
        $this->addSql('ALTER TABLE calendar_event DROP CONSTRAINT FK_57FA09C9A40A2C8');
        $this->addSql('ALTER TABLE calendar_event DROP CONSTRAINT FK_57FA09C9232D562B');
        $this->addSql('ALTER TABLE calendar_event DROP CONSTRAINT FK_57FA09C9537A1329');
        $this->addSql('ALTER TABLE calendar_invitation DROP CONSTRAINT FK_6757A5A7537A1329');
        $this->addSql('ALTER TABLE calendar_object DROP CONSTRAINT FK_2CBB3B0A40A2C8');
        $this->addSql('ALTER TABLE calendar_operation DROP CONSTRAINT FK_5AED2D274FA8B546');
        $this->addSql('ALTER TABLE calendar_operation DROP CONSTRAINT FK_5AED2D27A40A2C8');
        $this->addSql('ALTER TABLE calendar_operation DROP CONSTRAINT FK_5AED2D27232D562B');
        $this->addSql('DROP TABLE calendar');
        $this->addSql('DROP TABLE calendar_event');
        $this->addSql('DROP TABLE calendar_invitation');
        $this->addSql('DROP TABLE calendar_object');
        $this->addSql('DROP TABLE calendar_operation');
    }
}
