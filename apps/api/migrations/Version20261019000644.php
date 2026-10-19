<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019000644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, mail accounts and metadata, smart inbox categories, todos, signatures, settings, OAuth2';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(320) NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(120) NOT NULL, wrapped_data_key TEXT DEFAULT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE attachment (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, content_type VARCHAR(255) NOT NULL, size BIGINT NOT NULL, sha256 VARCHAR(64) NOT NULL, content_id VARCHAR(255) DEFAULT NULL, inline BOOLEAN NOT NULL, scan_status VARCHAR(16) NOT NULL, scan_signature VARCHAR(255) DEFAULT NULL, mail_account_id UUID NOT NULL, message_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_795FD9BB537A1329 ON attachment (message_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BB5CC814F7 ON attachment (sha256)');
        $this->addSql('CREATE INDEX IDX_795FD9BB4FA8B546 ON attachment (mail_account_id)');
        $this->addSql('CREATE TABLE category (id UUID NOT NULL, builtin VARCHAR(16) DEFAULT NULL, name VARCHAR(80) NOT NULL, instructions TEXT NOT NULL, include_senders JSON NOT NULL, exclude_senders JSON NOT NULL, verdict VARCHAR(16) NOT NULL, auto_create_todo BOOLEAN NOT NULL, batch_delivery BOOLEAN NOT NULL, notify BOOLEAN NOT NULL, position INT NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C17E3C61F972B51F7A ON category (owner_id, builtin)');
        $this->addSql('CREATE INDEX IDX_64C19C17E3C61F9 ON category (owner_id)');
        $this->addSql('CREATE TABLE folder (id UUID NOT NULL, remote_name VARCHAR(512) NOT NULL, display_name VARCHAR(255) NOT NULL, role VARCHAR(16) NOT NULL, uid_validity INT DEFAULT NULL, sync_cursor VARCHAR(2048) DEFAULT NULL, mail_account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ECA209CD4FA8B546DBDAD2DF ON folder (mail_account_id, remote_name)');
        $this->addSql('CREATE INDEX IDX_ECA209CD4FA8B546 ON folder (mail_account_id)');
        $this->addSql('CREATE TABLE mail_account (id UUID NOT NULL, email_address VARCHAR(320) NOT NULL, display_name VARCHAR(120) NOT NULL, provider VARCHAR(16) NOT NULL, connection JSON NOT NULL, encrypted_credentials TEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, leased_by VARCHAR(120) DEFAULT NULL, lease_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A78BD7CB7B00651CA22D4909 ON mail_account (status, lease_expires_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A78BD7CB7E3C61F9B08E074E ON mail_account (owner_id, email_address)');
        $this->addSql('CREATE INDEX IDX_A78BD7CB7E3C61F9 ON mail_account (owner_id)');
        $this->addSql('CREATE TABLE message (id UUID NOT NULL, message_id_header VARCHAR(998) DEFAULT NULL, in_reply_to VARCHAR(998) DEFAULT NULL, subject VARCHAR(998) NOT NULL, from_address VARCHAR(320) NOT NULL, from_name VARCHAR(255) DEFAULT NULL, recipients JSON NOT NULL, snippet VARCHAR(512) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seen BOOLEAN NOT NULL, flagged BOOLEAN NOT NULL, answered BOOLEAN NOT NULL, draft BOOLEAN NOT NULL, has_attachments BOOLEAN NOT NULL, size BIGINT NOT NULL, raw_object_key VARCHAR(255) NOT NULL, verdict VARCHAR(16) DEFAULT NULL, summary VARCHAR(512) DEFAULT NULL, classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mail_account_id UUID NOT NULL, thread_id UUID NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B6BD307F4FA8B5466D4F7F99 ON message (mail_account_id, received_at)');
        $this->addSql('CREATE INDEX IDX_B6BD307F4FA8B54622FC1B1A ON message (mail_account_id, message_id_header)');
        $this->addSql('CREATE INDEX IDX_B6BD307FE2904019 ON message (thread_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307F4FA8B546 ON message (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307F12469DE2 ON message (category_id)');
        $this->addSql('CREATE TABLE message_location (id UUID NOT NULL, remote_uid VARCHAR(255) NOT NULL, mod_seq BIGINT DEFAULT NULL, mail_account_id UUID NOT NULL, message_id UUID NOT NULL, folder_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_57B84DDA537A1329 ON message_location (message_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_57B84DDA162CB942CF070754 ON message_location (folder_id, remote_uid)');
        $this->addSql('CREATE INDEX IDX_57B84DDA4FA8B546 ON message_location (mail_account_id)');
        $this->addSql('CREATE INDEX IDX_57B84DDA162CB942 ON message_location (folder_id)');
        $this->addSql('CREATE TABLE oauth2_access_token (identifier CHAR(80) NOT NULL, expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked BOOLEAN NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier))');
        $this->addSql('CREATE INDEX IDX_454D9673C7440455 ON oauth2_access_token (client)');
        $this->addSql('CREATE TABLE oauth2_authorization_code (identifier CHAR(80) NOT NULL, expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked BOOLEAN NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier))');
        $this->addSql('CREATE INDEX IDX_509FEF5FC7440455 ON oauth2_authorization_code (client)');
        $this->addSql('CREATE TABLE oauth2_client (name VARCHAR(128) NOT NULL, secret VARCHAR(128) DEFAULT NULL, redirect_uris TEXT DEFAULT NULL, grants TEXT DEFAULT NULL, scopes TEXT DEFAULT NULL, active BOOLEAN NOT NULL, allow_plain_text_pkce BOOLEAN DEFAULT false NOT NULL, identifier VARCHAR(32) NOT NULL, PRIMARY KEY (identifier))');
        $this->addSql('CREATE TABLE oauth2_refresh_token (identifier CHAR(80) NOT NULL, expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked BOOLEAN NOT NULL, access_token CHAR(80) DEFAULT NULL, PRIMARY KEY (identifier))');
        $this->addSql('CREATE INDEX IDX_4DD90732B6A2DD68 ON oauth2_refresh_token (access_token)');
        $this->addSql('CREATE TABLE signature (id UUID NOT NULL, name VARCHAR(120) NOT NULL, html TEXT NOT NULL, is_default BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, mail_account_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AE8801417E3C61F9 ON signature (owner_id)');
        $this->addSql('CREATE INDEX IDX_AE8801414FA8B546 ON signature (mail_account_id)');
        $this->addSql('CREATE TABLE thread (id UUID NOT NULL, subject VARCHAR(998) NOT NULL, last_message_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_count INT NOT NULL, mail_account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_31204C834FA8B5466F60E3AF ON thread (mail_account_id, last_message_at)');
        $this->addSql('CREATE INDEX IDX_31204C834FA8B546 ON thread (mail_account_id)');
        $this->addSql('CREATE TABLE todo (id UUID NOT NULL, title VARCHAR(500) NOT NULL, notes TEXT NOT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, source VARCHAR(16) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, message_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5A0EB6A07E3C61F9540ED13A5303022F ON todo (owner_id, completed_at, due_at)');
        $this->addSql('CREATE INDEX IDX_5A0EB6A07E3C61F9 ON todo (owner_id)');
        $this->addSql('CREATE INDEX IDX_5A0EB6A0537A1329 ON todo (message_id)');
        $this->addSql('CREATE TABLE user_settings (values JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BB4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BB537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C17E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE folder ADD CONSTRAINT FK_ECA209CD4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mail_account ADD CONSTRAINT FK_A78BD7CB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FE2904019 FOREIGN KEY (thread_id) REFERENCES thread (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message_location ADD CONSTRAINT FK_57B84DDA4FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message_location ADD CONSTRAINT FK_57B84DDA537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE message_location ADD CONSTRAINT FK_57B84DDA162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth2_access_token ADD CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_authorization_code ADD CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth2_refresh_token ADD CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE signature ADD CONSTRAINT FK_AE8801417E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE signature ADD CONSTRAINT FK_AE8801414FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE thread ADD CONSTRAINT FK_31204C834FA8B546 FOREIGN KEY (mail_account_id) REFERENCES mail_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE todo ADD CONSTRAINT FK_5A0EB6A07E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE todo ADD CONSTRAINT FK_5A0EB6A0537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_settings ADD CONSTRAINT FK_5C844C5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attachment DROP CONSTRAINT FK_795FD9BB4FA8B546');
        $this->addSql('ALTER TABLE attachment DROP CONSTRAINT FK_795FD9BB537A1329');
        $this->addSql('ALTER TABLE category DROP CONSTRAINT FK_64C19C17E3C61F9');
        $this->addSql('ALTER TABLE folder DROP CONSTRAINT FK_ECA209CD4FA8B546');
        $this->addSql('ALTER TABLE mail_account DROP CONSTRAINT FK_A78BD7CB7E3C61F9');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307F4FA8B546');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307FE2904019');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307F12469DE2');
        $this->addSql('ALTER TABLE message_location DROP CONSTRAINT FK_57B84DDA4FA8B546');
        $this->addSql('ALTER TABLE message_location DROP CONSTRAINT FK_57B84DDA537A1329');
        $this->addSql('ALTER TABLE message_location DROP CONSTRAINT FK_57B84DDA162CB942');
        $this->addSql('ALTER TABLE oauth2_access_token DROP CONSTRAINT FK_454D9673C7440455');
        $this->addSql('ALTER TABLE oauth2_authorization_code DROP CONSTRAINT FK_509FEF5FC7440455');
        $this->addSql('ALTER TABLE oauth2_refresh_token DROP CONSTRAINT FK_4DD90732B6A2DD68');
        $this->addSql('ALTER TABLE signature DROP CONSTRAINT FK_AE8801417E3C61F9');
        $this->addSql('ALTER TABLE signature DROP CONSTRAINT FK_AE8801414FA8B546');
        $this->addSql('ALTER TABLE thread DROP CONSTRAINT FK_31204C834FA8B546');
        $this->addSql('ALTER TABLE todo DROP CONSTRAINT FK_5A0EB6A07E3C61F9');
        $this->addSql('ALTER TABLE todo DROP CONSTRAINT FK_5A0EB6A0537A1329');
        $this->addSql('ALTER TABLE user_settings DROP CONSTRAINT FK_5C844C5A76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE folder');
        $this->addSql('DROP TABLE mail_account');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE message_location');
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_client');
        $this->addSql('DROP TABLE oauth2_refresh_token');
        $this->addSql('DROP TABLE signature');
        $this->addSql('DROP TABLE thread');
        $this->addSql('DROP TABLE todo');
        $this->addSql('DROP TABLE user_settings');
    }
}
