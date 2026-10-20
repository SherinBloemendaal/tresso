<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020130446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hand-overs keep their conversation and the forward Message-ID, and use a forward once';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_7ae4c99c16ed395a');
        $this->addSql('ALTER TABLE hand_over ADD forward_message_id VARCHAR(998) DEFAULT NULL');
        $this->addSql('ALTER TABLE hand_over ADD thread_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE hand_over ADD CONSTRAINT FK_7AE4C99CE2904019 FOREIGN KEY (thread_id) REFERENCES thread (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7AE4C99C16ED395A ON hand_over (forward_id)');
        $this->addSql('CREATE INDEX IDX_7AE4C99CE2904019 ON hand_over (thread_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hand_over DROP CONSTRAINT FK_7AE4C99CE2904019');
        $this->addSql('DROP INDEX UNIQ_7AE4C99C16ED395A');
        $this->addSql('DROP INDEX IDX_7AE4C99CE2904019');
        $this->addSql('ALTER TABLE hand_over DROP forward_message_id');
        $this->addSql('ALTER TABLE hand_over DROP thread_id');
        $this->addSql('CREATE INDEX idx_7ae4c99c16ed395a ON hand_over (forward_id)');
    }
}
