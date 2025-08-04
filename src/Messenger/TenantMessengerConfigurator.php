<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

/**
 * Configures messenger settings based on tenant context.
 *
 * This service provides tenant-specific messenger configuration
 * by retrieving settings from the tenant settings manager with
 * fallback support from bundle configuration.
 */
class TenantMessengerConfigurator
{
    public function __construct(
        private readonly TenantSettingsManager $settingsManager,
        private readonly string $fallbackDsn = 'sync://',
        private readonly string $fallbackBus = 'messenger.bus.default',
    ) {
    }

    /**
     * Gets the messenger transport DSN for the current tenant.
     *
     * @param string|null $default Default DSN if tenant setting is not found
     *
     * @return string The messenger transport DSN, fallback, or default value
     */
    public function getTransportDsn(?string $default = null): string
    {
        $fallback = $default ?? $this->fallbackDsn;
        $value = $this->settingsManager->get('messenger_transport_dsn', $fallback);

        return is_string($value) ? $value : $fallback;
    }

    /**
     * Gets the messenger bus name for the current tenant.
     *
     * @param string|null $default Default bus name if tenant setting is not found
     *
     * @return string The messenger bus name, fallback, or default value
     */
    public function getBusName(?string $default = null): string
    {
        $fallback = $default ?? $this->fallbackBus;
        $value = $this->settingsManager->get('messenger_bus', $fallback);

        return is_string($value) ? $value : $fallback;
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
