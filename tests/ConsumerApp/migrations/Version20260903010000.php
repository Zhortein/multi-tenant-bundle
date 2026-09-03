<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an ordered tenant:migrate distribution probe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consumer_migration_probe (sequence INT NOT NULL, marker VARCHAR(64) NOT NULL, PRIMARY KEY(sequence))');
        $this->addSql("INSERT INTO consumer_migration_probe (sequence, marker) VALUES (1, 'tenant-migrate')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE consumer_migration_probe');
    }
}
