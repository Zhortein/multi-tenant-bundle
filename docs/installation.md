# Installation & Setup

This guide walks you through installing and configuring the Zhortein Multi-Tenant Bundle for Symfony 7+ applications.

## Requirements

- **PHP**: >= 8.3
- **Symfony**: >= 7.0
- **PostgreSQL**: >= 16 (recommended)
- **Doctrine ORM**: >= 3.0

## Installation

### 1. Install via Composer

```bash
composer require zhortein/multi-tenant-bundle
```

### 2. Enable the Bundle

The bundle should be automatically registered in `config/bundles.php`. If not, add it manually:

```php
<?php
// config/bundles.php

return [
    // ... other bundles
    Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle::class => ['all' => true],
];
```

### 3. Create Your Tenant Entity

Create a tenant entity that implements `TenantInterface`:

```php
<?php
// src/Entity/Tenant.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): void
    {
        $this->domain = $domain;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

### 4. Configure the Bundle

Create the bundle configuration file:

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    # Tenant entity class
    tenant_entity: 'App\Entity\Tenant'
    
    # Tenant resolution strategy
    resolver:
        type: 'subdomain'  # 'subdomain', 'path', 'header', or 'custom'
        options:
            base_domain: 'example.com'
    
    # Database strategy
    database:
        strategy: 'shared_db'  # 'shared_db' or 'multi_db'
        enable_filter: true
        connection_prefix: 'tenant_'
    
    # Whether tenant context is required
    require_tenant: true
```

### 5. Configure Doctrine

Update your Doctrine configuration to enable the tenant filter:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
                driver: 'pdo_pgsql'
                server_version: '16'
                charset: utf8
    
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                mappings:
                    App:
                        type: attribute
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
                filters:
                    tenant_filter:
                        class: Zhortein\MultiTenantBundle\Doctrine\Filter\TenantFilter
                        enabled: true
```

### 6. Create Database Schema

Run the following commands to create your database schema:

```bash
# Create database
php bin/console doctrine:database:create

# Generate migration for tenant entity
php bin/console make:migration

# Run migrations
php bin/console doctrine:migrations:migrate
```

## Creating Tenant-Aware Entities

### Shared Database Mode

For shared database mode, entities need a tenant_id field:

```php
<?php
// src/Entity/Product.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Entity\TenantAwareEntityTrait;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[AsTenantAware]
class Product
{
    use TenantAwareEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    // ... getters and setters
}
```

## Initial Setup

### Create Initial Tenants

Create a fixture to set up initial tenants:

```php
<?php
// src/DataFixtures/TenantFixtures.php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Tenant;

class TenantFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tenants = [
            ['slug' => 'acme', 'name' => 'ACME Corporation', 'domain' => 'acme.example.com'],
            ['slug' => 'demo', 'name' => 'Demo Company', 'domain' => 'demo.example.com'],
        ];

        foreach ($tenants as $tenantData) {
            $tenant = new Tenant();
            $tenant->setSlug($tenantData['slug']);
            $tenant->setName($tenantData['name']);
            $tenant->setDomain($tenantData['domain']);
            $tenant->setActive(true);

            $manager->persist($tenant);
        }

        $manager->flush();
    }
}
```

Load the fixtures:

```bash
php bin/console doctrine:fixtures:load
```

## Verification

### Check Configuration

```bash
# Verify bundle configuration
php bin/console debug:config zhortein_multi_tenant

# Check registered services
php bin/console debug:container tenant
```

## Next Steps

After installation, you can:

1. [Configure tenant resolution](tenant-resolution.md)
2. [Set up tenant-aware entities](doctrine-tenant-filter.md)
3. [Configure tenant settings](tenant-settings.md)
4. [Set up tenant-aware services](mailer.md)
5. [Create fixtures and migrations](fixtures.md)