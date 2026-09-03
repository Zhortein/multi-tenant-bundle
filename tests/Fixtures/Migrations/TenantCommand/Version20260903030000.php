<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Migrations\TenantCommand;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fail in a controlled way for tenant migration error coverage.';
    }

    public function up(Schema $schema): void
    {
        throw new \RuntimeException('Controlled tenant migration failure.');
    }

    public function down(Schema $schema): void
    {
    }
}
