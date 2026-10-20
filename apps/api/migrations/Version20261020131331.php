<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020131331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bulk actions walk conversations in id order per smart inbox or account, and remember the account';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bulk_action ADD resume_account UUID DEFAULT NULL');
        $this->addSql('DROP INDEX idx_31204c8312469de26f60e3af');
        $this->addSql('CREATE INDEX IDX_31204C8312469DE2BF396750 ON thread (category_id, id)');
        $this->addSql('CREATE INDEX IDX_31204C834FA8B546BF396750 ON thread (mail_account_id, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bulk_action DROP resume_account');
        $this->addSql('DROP INDEX IDX_31204C8312469DE2BF396750');
        $this->addSql('DROP INDEX IDX_31204C834FA8B546BF396750');
        $this->addSql('CREATE INDEX idx_31204c8312469de26f60e3af ON thread (category_id, last_message_at)');
    }
}
