<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Migrations\TenantCommand;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend the tenant migration command probe in migration order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_migration_probe ADD COLUMN applied_order INT DEFAULT 0 NOT NULL');
        $this->addSql("INSERT INTO tenant_migration_probe (sequence, marker, applied_order) VALUES (2, 'second', 2)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM tenant_migration_probe WHERE sequence = 2');
        $this->addSql('ALTER TABLE tenant_migration_probe DROP COLUMN applied_order');
    }
}
