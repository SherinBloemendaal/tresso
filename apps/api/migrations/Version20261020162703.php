<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020162703 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shredded marker and data key version on users, sealed mail notification bodies, plaintext objects to purge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE legacy_object (object_key VARCHAR(255) NOT NULL, PRIMARY KEY (object_key))');
        $this->addSql('ALTER TABLE app_user ADD data_key_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD keys_shredded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mail_notification ADD sealed_body TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE legacy_object');
        $this->addSql('ALTER TABLE app_user DROP data_key_version');
        $this->addSql('ALTER TABLE app_user DROP keys_shredded_at');
        $this->addSql('ALTER TABLE mail_notification DROP sealed_body');
    }
}
