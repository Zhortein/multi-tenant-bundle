<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;

#[ORM\Entity]
#[ORM\Table(name: 'lifecycle_records')]
#[AsTenantAware(tenantField: 'tenantId')]
class LifecycleRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', length: 64)]
    private string $tenantId;

    #[ORM\Column(length: 64)]
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }
}
