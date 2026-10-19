<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019030446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Provider sign-in: grants with sealed tokens, pending authorization requests, longer folder cursors';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE provider_authorization_request (state_hash VARCHAR(64) NOT NULL, provider VARCHAR(16) NOT NULL, features JSON NOT NULL, requested_scopes JSON NOT NULL, code_verifier VARCHAR(128) NOT NULL, mail_account_id VARCHAR(36) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (state_hash))');
        $this->addSql('CREATE INDEX IDX_7310AE00F9D83E2 ON provider_authorization_request (expires_at)');
        $this->addSql('CREATE INDEX IDX_7310AE007E3C61F9 ON provider_authorization_request (owner_id)');
        $this->addSql('CREATE TABLE provider_grant (id UUID NOT NULL, provider VARCHAR(16) NOT NULL, subject VARCHAR(255) NOT NULL, email_address VARCHAR(320) NOT NULL, scopes JSON NOT NULL, encrypted_tokens TEXT NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9053D3387E3C61F992C4739CFBCE3E7A ON provider_grant (owner_id, provider, subject)');
        $this->addSql('CREATE INDEX IDX_9053D3387E3C61F9 ON provider_grant (owner_id)');
        $this->addSql('ALTER TABLE provider_authorization_request ADD CONSTRAINT FK_7310AE007E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE provider_grant ADD CONSTRAINT FK_9053D3387E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE folder ALTER sync_cursor TYPE TEXT');
        $this->addSql('ALTER TABLE mail_account ADD provider_grant_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_account ADD CONSTRAINT FK_A78BD7CB144EE71A FOREIGN KEY (provider_grant_id) REFERENCES provider_grant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A78BD7CB144EE71A ON mail_account (provider_grant_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE provider_authorization_request DROP CONSTRAINT FK_7310AE007E3C61F9');
        $this->addSql('ALTER TABLE provider_grant DROP CONSTRAINT FK_9053D3387E3C61F9');
        $this->addSql('DROP TABLE provider_authorization_request');
        $this->addSql('DROP TABLE provider_grant');
        $this->addSql('ALTER TABLE folder ALTER sync_cursor TYPE VARCHAR(2048)');
        $this->addSql('ALTER TABLE mail_account DROP CONSTRAINT FK_A78BD7CB144EE71A');
        $this->addSql('DROP INDEX IDX_A78BD7CB144EE71A');
        $this->addSql('ALTER TABLE mail_account DROP provider_grant_id');
    }
}
