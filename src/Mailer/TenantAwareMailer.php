<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Twig\Environment;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Tenant-aware mailer that automatically configures sender information
 * based on the current tenant context and provides templated email functionality.
 */
final readonly class TenantAwareMailer implements MailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private TenantMailerConfigurator $configurator,
        private TenantContextInterface $tenantContext,
        private ?Environment $twig = null,
        private bool $addTenantIdHeader = false,
        private bool $addTenantNameHeader = false,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        // Configure tenant-specific settings for Email messages
        if ($message instanceof Email) {
            $this->configureTenantEmail($message);
            $this->configureTenantMetadataHeaders($message);
        }

        $this->mailer->send($message, $envelope);
    }

    /**
     * Sends a templated email with tenant-specific configuration.
     *
     * @param string               $to           Recipient email address
     * @param string               $subject      Email subject
     * @param string               $template     Twig template path
     * @param array<string, mixed> $context      Template context variables
     * @param string|null          $fromOverride Override from address
     *
     * @throws \RuntimeException           When Twig is not available
     * @throws \Twig\Error\LoaderError     When template is not found
     * @throws \Twig\Error\RuntimeError    When template rendering fails
     * @throws \Twig\Error\SyntaxError     When template has syntax errors
     * @throws TransportExceptionInterface
     */
    public function sendTemplatedEmail(
        string $to,
        string $subject,
        string $template,
        array $context = [],
        ?string $fromOverride = null,
    ): void {
        if (null === $this->twig) {
            throw new \RuntimeException('Twig is required for templated emails. Please install symfony/twig-bundle.');
        }

        $tenant = $this->tenantContext->getTenant();

        // Enhance context with tenant variables
        $enhancedContext = $this->enhanceContextWithTenantData($context);

        // Try tenant-specific template first, fallback to default
        $templatePath = $this->resolveTemplatePath($template);

        // Render the email content
        $htmlContent = $this->twig->render($templatePath, $enhancedContext);

        // Create and configure email
        $email = (new Email())
            ->to($to)
            ->subject($subject)
            ->html($htmlContent);

        // Override from address if specified
        if (null !== $fromOverride) {
            $email->from($fromOverride);
        }

        $this->send($email);
    }

    /**
     * Configures tenant-specific email settings.
     */
    private function configureTenantEmail(Email $email): void
    {
        // Set from address if not already set and tenant has configuration
        if (empty($email->getFrom())) {
            $fromAddress = $this->configurator->getFromAddress();
            $senderName = $this->configurator->getSenderName();

            if (null !== $fromAddress) {
                $from = null !== $senderName
                    ? new Address($fromAddress, $senderName)
                    : new Address($fromAddress);

                $email->from($from);
            }
        }

        // Set reply-to if not already set and tenant has configuration
        if (empty($email->getReplyTo())) {
            $replyToAddress = $this->configurator->getReplyToAddress();
            if (null !== $replyToAddress) {
                $email->replyTo($replyToAddress);
            }
        }

        // Add BCC if configured
        $bccAddress = $this->configurator->getBccAddress();
        if (null !== $bccAddress && empty($email->getBcc())) {
            $email->bcc($bccAddress);
        }
    }

    private function configureTenantMetadataHeaders(Email $email): void
    {
        $tenant = $this->tenantContext->getTenant();
        if (null === $tenant) {
            return;
        }

        $headers = $email->getHeaders();

        if ($this->addTenantIdHeader && !$headers->has('X-Tenant-ID')) {
            $headers->addTextHeader('X-Tenant-ID', $this->safeHeaderValue($tenant->getSlug(), 'tenant identifier'));
        }

        if ($this->addTenantNameHeader && !$headers->has('X-Tenant-Name')) {
            $headers->addTextHeader('X-Tenant-Name', $this->safeHeaderValue($this->tenantName($tenant), 'tenant name'));
        }
    }

    private function tenantName(\Zhortein\MultiTenantBundle\Entity\TenantInterface $tenant): string
    {
        if (method_exists($tenant, 'getName')) {
            $name = $tenant->getName();
            if (is_string($name) && '' !== $name) {
                return $name;
            }
        }

        return $tenant->getSlug();
    }

    private function safeHeaderValue(string $value, string $label): string
    {
        if ('' === $value || preg_match('/[\x00\r\n]/', $value)) {
            throw new \InvalidArgumentException(sprintf('The %s is not safe for an email header.', $label));
        }

        return $value;
    }

    /**
     * Enhances template context with tenant-specific data.
     *
     * @param array<string, mixed> $context Original context
     *
     * @return array<string, mixed> Enhanced context with tenant data
     */
    private function enhanceContextWithTenantData(array $context): array
    {
        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            return $context;
        }

        // Add tenant data to context
        $context['tenant'] = [
            'name' => $this->tenantName($tenant),
            'slug' => $tenant->getSlug(),
            'logoUrl' => $this->configurator->getLogoUrl(),
            'primaryColor' => $this->configurator->getPrimaryColor(),
            'websiteUrl' => $this->configurator->getWebsiteUrl(),
        ];

        return $context;
    }

    /**
     * Resolves the template path, trying tenant-specific template first.
     *
     * @param string $template Base template path
     *
     * @return string Resolved template path
     */
    private function resolveTemplatePath(string $template): string
    {
        if (null === $this->twig) {
            return $template;
        }

        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            return $template;
        }

        // Try tenant-specific template first
        $tenantTemplate = sprintf('emails/tenant/%s/%s', $tenant->getSlug(), $template);

        try {
            $this->twig->getLoader()->getSourceContext($tenantTemplate);

            return $tenantTemplate;
        } catch (\Twig\Error\LoaderError) {
            // Fallback to default template
            return $template;
        }
    }
}
