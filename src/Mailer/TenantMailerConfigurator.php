<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Mailer;

use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

/**
 * Configures mailer settings based on tenant context.
 *
 * This service provides tenant-specific mailer configuration
 * by retrieving settings from the tenant settings manager with
 * fallback support from bundle configuration.
 */
class TenantMailerConfigurator
{
    public function __construct(
        private readonly TenantSettingsManager $settingsManager,
        private readonly ?string $fallbackDsn = null,
        private readonly ?string $fallbackFrom = null,
        private readonly ?string $fallbackSender = null,
    ) {
    }

    /**
     * Gets the mailer DSN for the current tenant.
     *
     * @param string|null $default Default DSN if tenant setting is not found
     *
     * @return string|null The mailer DSN, fallback, or default value
     */
    public function getMailerDsn(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('mailer_dsn', $this->fallbackDsn ?? $default);

        return is_string($value) ? $value : ($this->fallbackDsn ?? $default);
    }

    /**
     * Gets the sender name for the current tenant.
     *
     * @param string|null $default Default sender name if tenant setting is not found
     *
     * @return string|null The sender name, fallback, or default value
     */
    public function getSenderName(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('email_sender', $this->fallbackSender ?? $default);

        return is_string($value) ? $value : ($this->fallbackSender ?? $default);
    }

    /**
     * Gets the from address for the current tenant.
     *
     * @param string|null $default Default from address if tenant setting is not found
     *
     * @return string|null The from address, fallback, or default value
     */
    public function getFromAddress(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('email_from', $this->fallbackFrom ?? $default);

        return is_string($value) ? $value : ($this->fallbackFrom ?? $default);
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

    /**
     * Gets the BCC address for the current tenant.
     *
     * @param string|null $default Default BCC address if tenant setting is not found
     *
     * @return string|null The BCC address or default value
     */
    public function getBccAddress(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('email_bcc', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the logo URL for the current tenant.
     *
     * @param string|null $default Default logo URL if tenant setting is not found
     *
     * @return string|null The logo URL or default value
     */
    public function getLogoUrl(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('logo_url', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the primary color for the current tenant.
     *
     * @param string|null $default Default primary color if tenant setting is not found
     *
     * @return string|null The primary color or default value
     */
    public function getPrimaryColor(?string $default = '#007bff'): ?string
    {
        $value = $this->settingsManager->get('primary_color', $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Gets the website URL for the current tenant.
     *
     * @param string|null $default Default website URL if tenant setting is not found
     *
     * @return string|null The website URL or default value
     */
    public function getWebsiteUrl(?string $default = null): ?string
    {
        $value = $this->settingsManager->get('website_url', $default);

        return is_string($value) ? $value : $default;
    }
}
