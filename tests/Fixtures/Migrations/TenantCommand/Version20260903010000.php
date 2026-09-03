<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Migrations\TenantCommand;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tenant migration command probe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenant_migration_probe (sequence INT NOT NULL, marker VARCHAR(64) NOT NULL, PRIMARY KEY(sequence))');
        $this->addSql("INSERT INTO tenant_migration_probe (sequence, marker) VALUES (1, 'first')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_migration_probe');
    }
}
