<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020123511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Daily briefs of know mail and when each user gets theirs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE daily_brief (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, covers_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, covers_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, thread_ids JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, emailed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9F9A71D37E3C61F9475BB34E ON daily_brief (owner_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_9F9A71D37E3C61F98DB31FBA ON daily_brief (owner_id, covers_until)');
        $this->addSql('CREATE INDEX IDX_9F9A71D37E3C61F9 ON daily_brief (owner_id)');
        $this->addSql('CREATE TABLE daily_brief_settings (enabled BOOLEAN NOT NULL, deliver_at VARCHAR(5) NOT NULL, next_run_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, covered_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE INDEX daily_brief_settings_due ON daily_brief_settings (next_run_at) WHERE (enabled = true)');
        $this->addSql('ALTER TABLE daily_brief ADD CONSTRAINT FK_9F9A71D37E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE daily_brief_settings ADD CONSTRAINT FK_217D84B3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_brief DROP CONSTRAINT FK_9F9A71D37E3C61F9');
        $this->addSql('ALTER TABLE daily_brief_settings DROP CONSTRAINT FK_217D84B3A76ED395');
        $this->addSql('DROP TABLE daily_brief');
        $this->addSql('DROP TABLE daily_brief_settings');
    }
}
