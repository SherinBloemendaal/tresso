<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019034943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The calendar sync status of mail accounts.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_account ADD calendar_status VARCHAR(16) DEFAULT \'unknown\' NOT NULL');
        $this->addSql('ALTER TABLE mail_account ADD calendar_status_detail VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_account DROP calendar_status');
        $this->addSql('ALTER TABLE mail_account DROP calendar_status_detail');
    }
}
