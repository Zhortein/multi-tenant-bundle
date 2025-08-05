<?php

namespace Zhortein\MultiTenantBundle\Helper;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

final readonly class TenantMailerHelper
{
    public function __construct(
        private MailerInterface       $mailer,
        private TenantSettingsManager $settings,
    ) {
    }

    /**
     * Crée un email enrichi tenant-aware (from/reply-to dynamiques).
     */
    public function createEmail(): TemplatedEmail
    {
        $email = new TemplatedEmail();

        $from = $this->settings->get('email_from', 'noreply@localhost');
        $replyTo = $this->settings->get('email_reply_to', $from);

        $email->from($from);
        $email->replyTo($replyTo);

        return $email;
    }

    public function send(TemplatedEmail $email): void
    {
        $this->mailer->send($email);
    }
}
