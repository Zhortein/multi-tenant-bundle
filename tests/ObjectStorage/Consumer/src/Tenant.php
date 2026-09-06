<?php

declare(strict_types=1);

namespace ObjectStorageConsumer;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
class Tenant implements TenantInterface
{
    public function __construct(#[ORM\Id] #[ORM\Column] private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return 'identical-slug';
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
