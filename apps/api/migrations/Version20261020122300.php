<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020122300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mail notifications: one per message that needs the user, delivered once';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mail_notification (id UUID NOT NULL, change_seq BIGINT DEFAULT 0 NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, fire_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, channels JSON NOT NULL, silent BOOLEAN NOT NULL, dropped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, message_id UUID DEFAULT NULL, thread_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_478207DE537A1329 ON mail_notification (message_id)');
        $this->addSql('CREATE INDEX IDX_478207DE16BA31DB9975CAE3 ON mail_notification (delivered_at, fire_at)');
        $this->addSql('CREATE INDEX IDX_478207DEA76ED395475BB34E ON mail_notification (user_id, change_seq)');
        $this->addSql('CREATE INDEX IDX_478207DEA76ED395 ON mail_notification (user_id)');
        $this->addSql('CREATE INDEX IDX_478207DEE2904019 ON mail_notification (thread_id)');
        $this->addSql('ALTER TABLE mail_notification ADD CONSTRAINT FK_478207DEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mail_notification ADD CONSTRAINT FK_478207DE537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mail_notification ADD CONSTRAINT FK_478207DEE2904019 FOREIGN KEY (thread_id) REFERENCES thread (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mail_notification DROP CONSTRAINT FK_478207DEA76ED395');
        $this->addSql('ALTER TABLE mail_notification DROP CONSTRAINT FK_478207DE537A1329');
        $this->addSql('ALTER TABLE mail_notification DROP CONSTRAINT FK_478207DEE2904019');
        $this->addSql('DROP TABLE mail_notification');
    }
}
