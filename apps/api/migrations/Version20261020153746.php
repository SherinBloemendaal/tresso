<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020153746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mail notifications that stand for several messages: the summary flag and the notifications folded into one';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_notification ADD summary BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE mail_notification ADD summarized_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_notification ADD CONSTRAINT FK_478207DE761F6B7D FOREIGN KEY (summarized_by_id) REFERENCES mail_notification (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_478207DE761F6B7D ON mail_notification (summarized_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_notification DROP CONSTRAINT FK_478207DE761F6B7D');
        $this->addSql('DROP INDEX IDX_478207DE761F6B7D');
        $this->addSql('ALTER TABLE mail_notification DROP summary');
        $this->addSql('ALTER TABLE mail_notification DROP summarized_by_id');
    }
}
