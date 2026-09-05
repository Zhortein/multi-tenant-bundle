<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\TenantMessage;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class ReproductionProbe
{
    public array $tenants = [];

    public function __construct(private TenantContextInterface $context)
    {
    }

    public function __invoke(TenantMessage $message): void
    {
        $this->tenants[] = $this->context->getTenant()?->getId();
    }
}
