<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019005258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Threading: message references and normalized thread subjects';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message_reference (id UUID NOT NULL, referenced_message_id VARCHAR(998) NOT NULL, mail_account_id UUID NOT NULL, message_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C41A4F254FA8B546B3BD336F ON message_reference (mail_account_id, referenced_message_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C41A4F25537A1329B3BD336F ON message_reference (message_id, referenced_message_id)');
        $this->addSql('CREATE INDEX IDX_C41A4F254FA8B546 ON message_reference (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_C41A4F25537A1329 ON message_reference (message_id)');
        $this->addSql('ALTER TABLE message_reference ADD CONSTRAINT FK_C41A4F254FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message_reference ADD CONSTRAINT FK_C41A4F25537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE thread ADD normalized_subject VARCHAR(255) NOT NULL');
        $this->addSql('CREATE INDEX IDX_31204C834FA8B5463A649E3C ON thread (mail_account_id, normalized_subject)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_reference DROP CONSTRAINT FK_C41A4F254FA8B546');
        $this->addSql('ALTER TABLE message_reference DROP CONSTRAINT FK_C41A4F25537A1329');
        $this->addSql('DROP TABLE message_reference');
        $this->addSql('DROP INDEX IDX_31204C834FA8B5463A649E3C');
        $this->addSql('ALTER TABLE thread DROP normalized_subject');
    }
}
