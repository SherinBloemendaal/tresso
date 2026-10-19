<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019151612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification deliveries keyed by a delivery key per user, and channels per kind of notification';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_delivery DROP CONSTRAINT fk_6291d4b5ef1a9d84');
        $this->addSql('DROP INDEX idx_6291d4b5ef1a9d84');
        $this->addSql('DROP INDEX uniq_6291d4b5ef1a9d84466f2ffcc5eeea34');
        $this->addSql('ALTER TABLE notification_delivery ADD delivery_key VARCHAR(190) NOT NULL');
        $this->addSql('ALTER TABLE notification_delivery DROP round');
        $this->addSql('ALTER TABLE notification_delivery RENAME COLUMN notification_id TO user_id');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT FK_6291D4B5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6291D4B5AD3FC00D466F2FFC ON notification_delivery (delivery_key, target)');
        $this->addSql('CREATE INDEX IDX_6291D4B5A76ED395 ON notification_delivery (user_id)');
        $this->addSql('ALTER TABLE notification_preferences ADD channels_by_kind JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_delivery DROP CONSTRAINT FK_6291D4B5A76ED395');
        $this->addSql('DROP INDEX UNIQ_6291D4B5AD3FC00D466F2FFC');
        $this->addSql('DROP INDEX IDX_6291D4B5A76ED395');
        $this->addSql('ALTER TABLE notification_delivery ADD round INT NOT NULL');
        $this->addSql('ALTER TABLE notification_delivery DROP delivery_key');
        $this->addSql('ALTER TABLE notification_delivery RENAME COLUMN user_id TO notification_id');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT fk_6291d4b5ef1a9d84 FOREIGN KEY (notification_id) REFERENCES scheduled_notification (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_6291d4b5ef1a9d84 ON notification_delivery (notification_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_6291d4b5ef1a9d84466f2ffcc5eeea34 ON notification_delivery (notification_id, target, round)');
        $this->addSql('ALTER TABLE notification_preferences DROP channels_by_kind');
    }
}
