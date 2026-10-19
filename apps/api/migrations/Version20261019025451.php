<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019025451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signatures: default signature per mail account, change numbers for client sync';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_account ADD default_signature_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_account ADD CONSTRAINT FK_A78BD7CBCFB7FD90 FOREIGN KEY (default_signature_id) REFERENCES signature (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A78BD7CBCFB7FD90 ON mail_account (default_signature_id)');
        $this->addSql('ALTER TABLE signature DROP CONSTRAINT fk_ae8801414fa8b546');
        $this->addSql('DROP INDEX idx_ae8801414fa8b546');
        $this->addSql('ALTER TABLE signature ADD change_seq BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE signature DROP is_default');
        $this->addSql('ALTER TABLE signature DROP mail_account_id');
        $this->addSql('CREATE INDEX IDX_AE8801417E3C61F9475BB34E ON signature (owner_id, change_seq)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_account DROP CONSTRAINT FK_A78BD7CBCFB7FD90');
        $this->addSql('DROP INDEX IDX_A78BD7CBCFB7FD90');
        $this->addSql('ALTER TABLE mail_account DROP default_signature_id');
        $this->addSql('DROP INDEX IDX_AE8801417E3C61F9475BB34E');
        $this->addSql('ALTER TABLE signature ADD is_default BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE signature ADD mail_account_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE signature DROP change_seq');
        $this->addSql('ALTER TABLE signature ADD CONSTRAINT fk_ae8801414fa8b546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_ae8801414fa8b546 ON signature (mail_account_id)');
    }
}
