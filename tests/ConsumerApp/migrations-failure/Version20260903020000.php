<?php

declare(strict_types=1);

namespace DoctrineMigrationsFailure;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Raise a controlled Consumer App tenant migration failure.';
    }

    public function up(Schema $schema): void
    {
        throw new \RuntimeException('Controlled Consumer App tenant migration failure.');
    }

    public function down(Schema $schema): void
    {
    }
}
