<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020132909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Online meeting providers per calendar, and whether a provider calendar write tells attendees, with the reply comment';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar ADD conference_providers JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE calendar_operation ADD notify BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE calendar_operation ADD comment VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar DROP conference_providers');
        $this->addSql('ALTER TABLE calendar_operation DROP notify');
        $this->addSql('ALTER TABLE calendar_operation DROP comment');
    }
}
