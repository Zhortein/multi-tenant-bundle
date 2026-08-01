<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column]
    private string $id;

    #[ORM\Column(unique: true)]
    private string $slug;

    public function __construct(string $id = 'fixture', string $slug = 'fixture')
    {
        $this->id = $id;
        $this->slug = $slug;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return sprintf('Consumer %s', $this->slug);
    }

    public function getDomain(): ?string
    {
        return null;
    }

    public function isActive(): bool
    {
        return true;
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
