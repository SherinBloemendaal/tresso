<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020181805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time a todo holds in the calendar, and the setting that plans todos automatically';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduling_preferences ADD plan_todos BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE todo ADD planned_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE todo ADD planned_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE todo ADD plan_state VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_5A0EB6A07E3C61F9117CE21B ON todo (owner_id, planned_start)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE scheduling_preferences DROP plan_todos');
        $this->addSql('DROP INDEX IDX_5A0EB6A07E3C61F9117CE21B');
        $this->addSql('ALTER TABLE todo DROP planned_start');
        $this->addSql('ALTER TABLE todo DROP planned_end');
        $this->addSql('ALTER TABLE todo DROP plan_state');
    }
}
