<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

/**
 * Represents a tenant-specific setting.
 *
 * This entity stores key-value pairs for tenant configuration,
 * ensuring each tenant can have its own settings.
 */
#[ORM\Entity(repositoryClass: TenantSettingRepository::class)]
#[ORM\Table(name: 'tenant_settings')]
#[ORM\UniqueConstraint(name: 'tenant_setting_unique', columns: ['tenant_id', 'setting_key'])]
class TenantSetting implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TenantInterface $tenant;

    #[ORM\Column(name: 'setting_key', type: 'string', length: 100)]
    private string $key;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getTenant(): TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
    }
}
