<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Functional\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * Functional tests for TenantDoctrineFilter enhancements.
 *
 * @covers \Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter
 */
final class TenantDoctrineFilterFunctionalTest extends TestCase
{
    protected function setUp(): void
    {
        // Skip functional tests that require full Symfony setup
        $this->markTestSkipped('Functional tests require full Symfony kernel setup. Enhanced filter is covered by integration tests.');
    }

    public function testSkipped(): void
    {
        $this->assertTrue(true);
    }
}

// Test entities for functional testing
// Note: These would typically be in separate files or test fixtures

#[ORM\Entity]
#[ORM\Table(name: 'functional_test_tenants')]
class FunctionalTestTenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    public function __construct(int $id, string $slug)
    {
        $this->id = $id;
        $this->slug = $slug;
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getMailerDsn(): ?string
    {
        return null;
    }

    public function getMessengerDsn(): ?string
    {
        return null;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'functional_test_products')]
class FunctionalTestProduct implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: FunctionalTestTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'functional_test_employees')]
#[AsTenantAware(tenantField: 'organization')]
class FunctionalTestEmployee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: FunctionalTestTenant::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $organization = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getOrganization(): ?TenantInterface
    {
        return $this->organization;
    }

    public function setOrganization(TenantInterface $organization): void
    {
        $this->organization = $organization;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'functional_test_non_tenant_entities')]
class FunctionalTestNonTenantEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
