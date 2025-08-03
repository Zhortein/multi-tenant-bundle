<?php

namespace Zhortein\MultiTenantBundle\Entity;

/**
 * Interface to be implemented by all tenant entities.
 */
interface TenantInterface
{
    public function getId(): string|int;

    public function getSlug(): string;

    public function getMailerDsn(): ?string;

    public function getMessengerDsn(): ?string;

}