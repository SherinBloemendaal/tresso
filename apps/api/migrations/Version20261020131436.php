<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020131436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todos in the client sync: due date and time, duration, one todo per email';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_5a0eb6a0537a1329');
        $this->addSql('DROP INDEX idx_5a0eb6a07e3c61f9540ed13a5303022f');
        $this->addSql('ALTER TABLE todo ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE todo ADD due_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE todo ADD due_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE todo ADD duration_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE todo DROP due_at');
        $this->addSql('CREATE INDEX IDX_5A0EB6A07E3C61F9540ED13AE673A031 ON todo (owner_id, completed_at, due_date)');
        $this->addSql('CREATE INDEX IDX_5A0EB6A07E3C61F9475BB34E ON todo (owner_id, change_seq)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5A0EB6A0537A1329 ON todo (message_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_5A0EB6A07E3C61F9540ED13AE673A031');
        $this->addSql('DROP INDEX IDX_5A0EB6A07E3C61F9475BB34E');
        $this->addSql('DROP INDEX UNIQ_5A0EB6A0537A1329');
        $this->addSql('ALTER TABLE todo ADD due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE todo DROP change_seq');
        $this->addSql('ALTER TABLE todo DROP due_date');
        $this->addSql('ALTER TABLE todo DROP due_time');
        $this->addSql('ALTER TABLE todo DROP duration_minutes');
        $this->addSql('CREATE INDEX idx_5a0eb6a0537a1329 ON todo (message_id)');
        $this->addSql('CREATE INDEX idx_5a0eb6a07e3c61f9540ed13a5303022f ON todo (owner_id, completed_at, due_at)');
    }
}
