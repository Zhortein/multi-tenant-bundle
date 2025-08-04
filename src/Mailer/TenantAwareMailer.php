<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Tenant-aware mailer that automatically configures sender information
 * based on the current tenant context.
 */
final class TenantAwareMailer implements MailerInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TenantMailerConfigurator $configurator,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        // Configure tenant-specific settings for Email messages
        if ($message instanceof Email) {
            $this->configureTenantEmail($message);
        }

        $this->mailer->send($message, $envelope);
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
            
            if ($fromAddress !== null) {
                $from = $senderName !== null 
                    ? new Address($fromAddress, $senderName)
                    : new Address($fromAddress);
                    
                $email->from($from);
            }
        }

        // Set reply-to if not already set and tenant has configuration
        if (empty($email->getReplyTo())) {
            $replyToAddress = $this->configurator->getReplyToAddress();
            if ($replyToAddress !== null) {
                $email->replyTo($replyToAddress);
            }
        }
    }
}