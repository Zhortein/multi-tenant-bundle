<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

/**
 * Configures messenger settings based on tenant context.
 *
 * This service provides tenant-specific messenger configuration
 * by retrieving settings from the tenant settings manager.
 */
class TenantMessengerConfigurator
{
    public function __construct(
        private readonly TenantSettingsManager $settingsManager,
    ) {
    }

    /**
     * Gets the messenger transport DSN for the current tenant.
     *
     * @param string $default Default DSN if tenant setting is not found
     *
     * @return string The messenger transport DSN
     */
    public function getTransportDsn(string $default = 'sync://'): string
    {
        $value = $this->settingsManager->get('messenger_transport_dsn', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the messenger bus name for the current tenant.
     *
     * @param string $default Default bus name if tenant setting is not found
     *
     * @return string The messenger bus name
     */
    public function getBusName(string $default = 'messenger.bus.default'): string
    {
        $value = $this->settingsManager->get('messenger_bus', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the delay for a specific transport for the current tenant.
     *
     * @param string|null $transport The transport name
     * @param int         $default   Default delay if tenant setting is not found
     *
     * @return int The delay in milliseconds
     */
    public function getDelay(?string $transport = null, int $default = 0): int
    {
        $key = $transport ? "messenger_delay_{$transport}" : 'messenger_delay';

        return (int) $this->settingsManager->get($key, $default);
    }
}
