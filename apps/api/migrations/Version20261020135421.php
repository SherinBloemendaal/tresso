<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020135421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-user keys, key versions of stored content, sealed snippets and body texts, keyed scan verdict cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_key (id UUID NOT NULL, version INT NOT NULL, wrapped_key TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6186CA227E3C61F9BF1CD3C3 ON user_key (owner_id, version)');
        $this->addSql('CREATE INDEX IDX_6186CA227E3C61F9 ON user_key (owner_id)');
        $this->addSql('ALTER TABLE user_key ADD CONSTRAINT FK_6186CA227E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD wrapped_index_key TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE attachment ADD key_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE draft_attachment ADD key_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD sealed_snippet TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD raw_key_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_text ADD sealed_text TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_text ALTER text DROP NOT NULL');
        $this->addSql('ALTER TABLE outgoing_message ADD raw_key_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE scan_result DROP CONSTRAINT scan_result_pkey');
        $this->addSql('ALTER TABLE scan_result ADD key_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE scan_result RENAME COLUMN sha256 TO content_key');
        $this->addSql('ALTER TABLE scan_result ADD PRIMARY KEY (content_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_key DROP CONSTRAINT FK_6186CA227E3C61F9');
        $this->addSql('DROP TABLE user_key');
        $this->addSql('ALTER TABLE app_user DROP wrapped_index_key');
        $this->addSql('ALTER TABLE attachment DROP key_version');
        $this->addSql('ALTER TABLE draft_attachment DROP key_version');
        $this->addSql('ALTER TABLE message DROP sealed_snippet');
        $this->addSql('ALTER TABLE message DROP raw_key_version');
        $this->addSql('ALTER TABLE message_text DROP sealed_text');
        $this->addSql('ALTER TABLE message_text ALTER text SET NOT NULL');
        $this->addSql('ALTER TABLE outgoing_message DROP raw_key_version');
        $this->addSql('ALTER TABLE scan_result DROP CONSTRAINT scan_result_pkey');
        $this->addSql('ALTER TABLE scan_result DROP key_id');
        $this->addSql('ALTER TABLE scan_result RENAME COLUMN content_key TO sha256');
        $this->addSql('ALTER TABLE scan_result ADD PRIMARY KEY (sha256)');
    }
}
