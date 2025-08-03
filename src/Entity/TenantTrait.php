<?php

namespace Zhortein\MultiTenantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

trait TenantTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $mailerDsn;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $messengerDsn;

    public function getId(): int
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

    public function getMailerDsn(): ?string
    {
        return null;
    }

    public function getMessengerDsn(): ?string
    {
        return null;
    }

}