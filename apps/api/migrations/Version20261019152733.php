<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019152733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Working hours, focus, travel and snooze settings, work calendars, important events, leave-now estimates from the phone instead of a stored position';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_location DROP CONSTRAINT fk_be136dcba76ed395');
        $this->addSql('DROP TABLE user_location');
        $this->addSql('ALTER TABLE calendar ADD work BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD important BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD work_days JSON NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD work_start VARCHAR(5) NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD work_end VARCHAR(5) NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD shift_early_reminders BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD work_calendars_quiet BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD hold_during_focus BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD hold_mail_in_meetings BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD leave_now BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD travel_mode VARCHAR(16) NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD extra_travel_minutes INT NOT NULL');
        $this->addSql('ALTER TABLE notification_preferences ADD snooze_choices JSON NOT NULL');
        $this->addSql('ALTER TABLE scheduled_notification ADD estimated_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_location (latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE user_location ADD CONSTRAINT fk_be136dcba76ed395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE calendar DROP work');
        $this->addSql('ALTER TABLE calendar_event DROP important');
        $this->addSql('ALTER TABLE notification_preferences DROP work_days');
        $this->addSql('ALTER TABLE notification_preferences DROP work_start');
        $this->addSql('ALTER TABLE notification_preferences DROP work_end');
        $this->addSql('ALTER TABLE notification_preferences DROP shift_early_reminders');
        $this->addSql('ALTER TABLE notification_preferences DROP work_calendars_quiet');
        $this->addSql('ALTER TABLE notification_preferences DROP hold_during_focus');
        $this->addSql('ALTER TABLE notification_preferences DROP hold_mail_in_meetings');
        $this->addSql('ALTER TABLE notification_preferences DROP leave_now');
        $this->addSql('ALTER TABLE notification_preferences DROP travel_mode');
        $this->addSql('ALTER TABLE notification_preferences DROP extra_travel_minutes');
        $this->addSql('ALTER TABLE notification_preferences DROP snooze_choices');
        $this->addSql('ALTER TABLE scheduled_notification DROP estimated_minutes');
    }
}
