<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\DriverManager;
use Symfony\Contracts\Service\ResetInterface;

/** @internal Process-local routing state used by the DBAL middleware. */
/** @phpstan-import-type Params from DriverManager */
final class DoctrineTenantConnectionRouter implements ResetInterface
{
    private TenantConnectionState $state;

    public function __construct(private readonly TenantConnectionParametersProviderInterface $provider)
    {
        $this->state = TenantConnectionState::none();
    }

    public function state(): TenantConnectionState
    {
        return $this->state;
    }

    public function activate(TenantConnectionState $state): void
    {
        $this->state = $state;
    }

    public function reset(): void
    {
        $this->state = TenantConnectionState::none();
    }

    /** @phpstan-return Params */
    public function parameters(): array
    {
        return $this->provider->parametersFor($this->state);
    }
}
