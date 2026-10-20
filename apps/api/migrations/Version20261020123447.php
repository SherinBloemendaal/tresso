<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020123447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduling preferences for the meeting time finder';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scheduling_preferences (buffer_minutes INT NOT NULL, travel_minutes INT NOT NULL, minimum_notice_minutes INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE scheduling_preferences ADD CONSTRAINT FK_AB50D9A7A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduling_preferences DROP CONSTRAINT FK_AB50D9A7A76ED395');
        $this->addSql('DROP TABLE scheduling_preferences');
    }
}
