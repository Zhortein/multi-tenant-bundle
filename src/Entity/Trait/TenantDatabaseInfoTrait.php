<?php

namespace Zhortein\MultiTenantBundle\Entity\Trait;

trait TenantDatabaseInfoTrait
{
    protected string $databaseName;
    protected string $databaseUser;
    protected string $databasePassword;
    protected ?string $databaseHost = '127.0.0.1';
    protected ?int $databasePort = 3306;
    protected ?string $databaseDriver = 'pdo_mysql';

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getDatabaseUser(): string
    {
        return $this->databaseUser;
    }

    public function getDatabasePassword(): string
    {
        return $this->databasePassword;
    }

    public function getDatabaseHost(): string
    {
        return $this->databaseHost ?? '127.0.0.1';
    }

    public function getDatabasePort(): int
    {
        return $this->databasePort ?? 3306;
    }

    public function getDatabaseDriver(): string
    {
        return $this->databaseDriver ?? 'pdo_mysql';
    }
}
