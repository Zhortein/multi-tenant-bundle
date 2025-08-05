<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

/**
 * @covers \Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer
 */
final class TenantAwareMailerTest extends TestCase
{
    private MailerInterface $mailer;
    private TenantMailerConfigurator $configurator;
    private TenantContextInterface $tenantContext;
    private TenantAwareMailer $tenantAwareMailer;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->configurator = $this->createMock(TenantMailerConfigurator::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->tenantAwareMailer = new TenantAwareMailer(
            $this->mailer,
            $this->configurator,
            $this->tenantContext
        );
    }

    public function testSendWithTenantConfiguration(): void
    {
        $email = new Email();
        $email->to('user@example.com')
              ->subject('Test Subject')
              ->text('Test Body');

        $this->configurator
            ->expects($this->once())
            ->method('getFromAddress')
            ->willReturn('tenant@example.com');

        $this->configurator
            ->expects($this->once())
            ->method('getSenderName')
            ->willReturn('Tenant Name');

        $this->configurator
            ->expects($this->once())
            ->method('getReplyToAddress')
            ->willReturn('reply@example.com');

        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) {
                $from = $email->getFrom();
                $replyTo = $email->getReplyTo();

                return 1 === count($from)
                    && 'tenant@example.com' === $from[0]->getAddress()
                    && 'Tenant Name' === $from[0]->getName()
                    && 1 === count($replyTo)
                    && 'reply@example.com' === $replyTo[0]->getAddress();
            }));

        $this->tenantAwareMailer->send($email);
    }

    public function testSendWithExistingFromAddress(): void
    {
        $email = new Email();
        $email->from('existing@example.com')
              ->to('user@example.com')
              ->subject('Test Subject')
              ->text('Test Body');

        $this->configurator
            ->expects($this->once())
            ->method('getReplyToAddress')
            ->willReturn('reply@example.com');

        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) {
                $from = $email->getFrom();
                $replyTo = $email->getReplyTo();

                return 1 === count($from)
                    && 'existing@example.com' === $from[0]->getAddress()
                    && 1 === count($replyTo)
                    && 'reply@example.com' === $replyTo[0]->getAddress();
            }));

        $this->tenantAwareMailer->send($email);
    }

    public function testSendWithNoTenantConfiguration(): void
    {
        $email = new Email();
        $email->to('user@example.com')
              ->subject('Test Subject')
              ->text('Test Body');

        $this->configurator
            ->expects($this->once())
            ->method('getFromAddress')
            ->willReturn(null);

        $this->configurator
            ->expects($this->once())
            ->method('getReplyToAddress')
            ->willReturn(null);

        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) {
                return empty($email->getFrom()) && empty($email->getReplyTo());
            }));

        $this->tenantAwareMailer->send($email);
    }
}
