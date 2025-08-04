<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

/**
 * @covers \Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer
 */
final class TenantAwareMailerTest extends TestCase
{
    private MailerInterface $mailer;
    private TenantMailerConfigurator $configurator;
    private TenantAwareMailer $tenantAwareMailer;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->configurator = $this->createMock(TenantMailerConfigurator::class);
        $this->tenantAwareMailer = new TenantAwareMailer($this->mailer, $this->configurator);
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
                
                return count($from) === 1
                    && $from[0]->getAddress() === 'tenant@example.com'
                    && $from[0]->getName() === 'Tenant Name'
                    && count($replyTo) === 1
                    && $replyTo[0]->getAddress() === 'reply@example.com';
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
                return count($from) === 1 
                    && $from[0]->getAddress() === 'existing@example.com'
                    && count($replyTo) === 1
                    && $replyTo[0]->getAddress() === 'reply@example.com';
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