<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020142703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hand-overs record the overdue notice the server delivered, for the desktop app';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hand_over ADD notify_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE hand_over ADD notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE hand_over ADD notified_channels JSON NOT NULL');
        $this->addSql('ALTER TABLE hand_over ADD notified_silently BOOLEAN NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hand_over DROP notify_at');
        $this->addSql('ALTER TABLE hand_over DROP notified_at');
        $this->addSql('ALTER TABLE hand_over DROP notified_channels');
        $this->addSql('ALTER TABLE hand_over DROP notified_silently');
    }
}
