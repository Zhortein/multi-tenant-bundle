<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Mailer;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

/**
 * Configures mailer settings based on tenant context.
 *
 * This service provides tenant-specific mailer configuration
 * by retrieving settings from the tenant settings manager.
 */
final class TenantMailerConfigurator
{
    public function __construct(
        private readonly TenantSettingsManager $settingsManager,
    ) {
    }

    /**
     * Gets the mailer DSN for the current tenant.
     *
     * @param string|null $default Default DSN if tenant setting is not found
     *
     * @return string|null The mailer DSN or default value
     */
    public function getMailerDsn(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('mailer_dsn', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the sender name for the current tenant.
     *
     * @param string|null $default Default sender name if tenant setting is not found
     *
     * @return string|null The sender name or default value
     */
    public function getSenderName(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('email_sender', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the from address for the current tenant.
     *
     * @param string|null $default Default from address if tenant setting is not found
     *
     * @return string|null The from address or default value
     */
    public function getFromAddress(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('email_from', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the reply-to address for the current tenant.
     *
     * @param string|null $default Default reply-to address if tenant setting is not found
     *
     * @return string|null The reply-to address or default value
     */
    public function getReplyToAddress(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('email_reply_to', $default);

        return is_string($value) ? $value : $default;
    }
}
