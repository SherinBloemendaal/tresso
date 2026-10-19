<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019071607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications: devices, preferences, reminder rules, planned notifications and their deliveries, user location, private event notes, and the travel time and default reminders of events.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event_note (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, occurrence_key VARCHAR(16) NOT NULL, text TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D076B6BFA76ED395475BB34E ON event_note (user_id, change_seq)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D076B6BF71F7E88B3E147BD6 ON event_note (event_id, occurrence_key)');
        $this->addSql('CREATE INDEX IDX_D076B6BFA76ED395 ON event_note (user_id)');
        $this->addSql('CREATE INDEX IDX_D076B6BF71F7E88B ON event_note (event_id)');
        $this->addSql('CREATE TABLE notification_delivery (id UUID NOT NULL, target VARCHAR(64) NOT NULL, round INT NOT NULL, status VARCHAR(16) NOT NULL, error VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notification_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6291D4B5EF1A9D84466F2FFCC5EEEA34 ON notification_delivery (notification_id, target, round)');
        $this->addSql('CREATE INDEX IDX_6291D4B5EF1A9D84 ON notification_delivery (notification_id)');
        $this->addSql('CREATE TABLE notification_device (id UUID NOT NULL, kind VARCHAR(16) NOT NULL, endpoint VARCHAR(2048) NOT NULL, public_key VARCHAR(128) DEFAULT NULL, auth_secret VARCHAR(64) DEFAULT NULL, name VARCHAR(120) NOT NULL, enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CD8848D3A76ED395C4420F7B ON notification_device (user_id, endpoint)');
        $this->addSql('CREATE INDEX IDX_CD8848D3A76ED395 ON notification_device (user_id)');
        $this->addSql('CREATE TABLE notification_preferences (time_zone VARCHAR(64) NOT NULL, quiet_hours_enabled BOOLEAN NOT NULL, quiet_days JSON NOT NULL, quiet_start VARCHAR(5) NOT NULL, quiet_end VARCHAR(5) NOT NULL, focus_during_busy BOOLEAN NOT NULL, email_address VARCHAR(320) DEFAULT NULL, planned_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE INDEX IDX_3CAA95B412F9217 ON notification_preferences (planned_until)');
        $this->addSql('CREATE TABLE reminder_rule (id UUID NOT NULL, name VARCHAR(120) NOT NULL, all_day BOOLEAN DEFAULT NULL, role VARCHAR(16) NOT NULL, with_attendees BOOLEAN DEFAULT NULL, with_conference BOOLEAN DEFAULT NULL, with_location BOOLEAN DEFAULT NULL, reminders JSON NOT NULL, leave_now BOOLEAN NOT NULL, brief_minutes INT DEFAULT NULL, follow_up BOOLEAN NOT NULL, position INT NOT NULL, user_id UUID NOT NULL, calendar_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_512470E5A76ED395462CE4F5 ON reminder_rule (user_id, position)');
        $this->addSql('CREATE INDEX IDX_512470E5A76ED395 ON reminder_rule (user_id)');
        $this->addSql('CREATE INDEX IDX_512470E5A40A2C8 ON reminder_rule (calendar_id)');
        $this->addSql('CREATE TABLE scheduled_notification (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, occurrence_key VARCHAR(16) NOT NULL, kind VARCHAR(16) NOT NULL, slot VARCHAR(32) NOT NULL, fire_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, occurrence_starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, channels JSON NOT NULL, silent BOOLEAN NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, round INT NOT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, event_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6930F65B16BA31DB9975CAE3 ON scheduled_notification (delivered_at, fire_at)');
        $this->addSql('CREATE INDEX IDX_6930F65BA76ED395475BB34E ON scheduled_notification (user_id, change_seq)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6930F65B71F7E88B3E147BD63BC4BCD9AC0E2067 ON scheduled_notification (event_id, occurrence_key, kind, slot)');
        $this->addSql('CREATE INDEX IDX_6930F65BA76ED395 ON scheduled_notification (user_id)');
        $this->addSql('CREATE INDEX IDX_6930F65B71F7E88B ON scheduled_notification (event_id)');
        $this->addSql('CREATE TABLE user_location (latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE event_note ADD CONSTRAINT FK_D076B6BFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_note ADD CONSTRAINT FK_D076B6BF71F7E88B FOREIGN KEY (event_id) REFERENCES calendar_event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT FK_6291D4B5EF1A9D84 FOREIGN KEY (notification_id) REFERENCES scheduled_notification (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_device ADD CONSTRAINT FK_CD8848D3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_preferences ADD CONSTRAINT FK_3CAA95B4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reminder_rule ADD CONSTRAINT FK_512470E5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reminder_rule ADD CONSTRAINT FK_512470E5A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scheduled_notification ADD CONSTRAINT FK_6930F65BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scheduled_notification ADD CONSTRAINT FK_6930F65B71F7E88B FOREIGN KEY (event_id) REFERENCES calendar_event (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_location ADD CONSTRAINT FK_BE136DCBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_event ADD travel_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD default_reminders BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_note DROP CONSTRAINT FK_D076B6BFA76ED395');
        $this->addSql('ALTER TABLE event_note DROP CONSTRAINT FK_D076B6BF71F7E88B');
        $this->addSql('ALTER TABLE notification_delivery DROP CONSTRAINT FK_6291D4B5EF1A9D84');
        $this->addSql('ALTER TABLE notification_device DROP CONSTRAINT FK_CD8848D3A76ED395');
        $this->addSql('ALTER TABLE notification_preferences DROP CONSTRAINT FK_3CAA95B4A76ED395');
        $this->addSql('ALTER TABLE reminder_rule DROP CONSTRAINT FK_512470E5A76ED395');
        $this->addSql('ALTER TABLE reminder_rule DROP CONSTRAINT FK_512470E5A40A2C8');
        $this->addSql('ALTER TABLE scheduled_notification DROP CONSTRAINT FK_6930F65BA76ED395');
        $this->addSql('ALTER TABLE scheduled_notification DROP CONSTRAINT FK_6930F65B71F7E88B');
        $this->addSql('ALTER TABLE user_location DROP CONSTRAINT FK_BE136DCBA76ED395');
        $this->addSql('DROP TABLE event_note');
        $this->addSql('DROP TABLE notification_delivery');
        $this->addSql('DROP TABLE notification_device');
        $this->addSql('DROP TABLE notification_preferences');
        $this->addSql('DROP TABLE reminder_rule');
        $this->addSql('DROP TABLE scheduled_notification');
        $this->addSql('DROP TABLE user_location');
        $this->addSql('ALTER TABLE calendar_event DROP travel_minutes');
        $this->addSql('ALTER TABLE calendar_event DROP default_reminders');
    }
}
