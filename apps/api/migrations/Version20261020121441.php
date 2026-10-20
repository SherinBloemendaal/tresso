<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020121441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attachment scanning: verdict cache by content hash, scan state of received and draft files, message scan summary, indexes for the pending sweep';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE scan_result (sha256 VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, signature VARCHAR(255) DEFAULT NULL, engine VARCHAR(255) NOT NULL, scanned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (sha256))');
        $this->addSql('ALTER TABLE attachment ADD scan_failure VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE attachment ADD scanned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE attachment ADD scan_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_795FD9BBA7B140E24ABC836D ON attachment (scan_status, scan_requested_at)');
        $this->addSql('ALTER TABLE draft_attachment ADD scan_failure VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE draft_attachment ADD scanned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE draft_attachment ADD scan_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_460AA8105CC814F7 ON draft_attachment (sha256)');
        $this->addSql('CREATE INDEX IDX_460AA810A7B140E24ABC836D ON draft_attachment (scan_status, scan_requested_at)');
        $this->addSql('ALTER TABLE message ADD attachment_scan VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE scan_result');
        $this->addSql('DROP INDEX IDX_795FD9BBA7B140E24ABC836D');
        $this->addSql('ALTER TABLE attachment DROP scan_failure');
        $this->addSql('ALTER TABLE attachment DROP scanned_at');
        $this->addSql('ALTER TABLE attachment DROP scan_requested_at');
        $this->addSql('DROP INDEX IDX_460AA8105CC814F7');
        $this->addSql('DROP INDEX IDX_460AA810A7B140E24ABC836D');
        $this->addSql('ALTER TABLE draft_attachment DROP scan_failure');
        $this->addSql('ALTER TABLE draft_attachment DROP scanned_at');
        $this->addSql('ALTER TABLE draft_attachment DROP scan_requested_at');
        $this->addSql('ALTER TABLE message DROP attachment_scan');
    }
}
