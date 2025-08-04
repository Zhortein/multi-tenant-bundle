<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;

/**
 * @covers \Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer
 */
class TenantAwareMailerTest extends TestCase
{
    private MailerInterface $innerMailer;
    private TenantMailerConfigurator $configurator;
    private TenantContextInterface $tenantContext;
    private Environment $twig;
    private TenantAwareMailer $mailer;

    protected function setUp(): void
    {
        $this->innerMailer = $this->createMock(MailerInterface::class);
        $this->configurator = $this->createMock(TenantMailerConfigurator::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->twig = $this->createMock(Environment::class);

        $this->mailer = new TenantAwareMailer(
            $this->innerMailer,
            $this->configurator,
            $this->tenantContext,
            $this->twig
        );
    }

    public function testSendEmailWithTenantConfiguration(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corporation');

        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->configurator->method('getFromAddress')->willReturn('noreply@acme.com');
        $this->configurator->method('getSenderName')->willReturn('Acme Corporation');
        $this->configurator->method('getReplyToAddress')->willReturn('support@acme.com');
        $this->configurator->method('getBccAddress')->willReturn('admin@acme.com');

        $email = new Email();
        $email->to('user@example.com')
              ->subject('Test Email')
              ->text('Test content');

        // Expect the inner mailer to be called with configured email
        $this->innerMailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $sentEmail) {
                $from = $sentEmail->getFrom();
                $replyTo = $sentEmail->getReplyTo();
                $bcc = $sentEmail->getBcc();
                $headers = $sentEmail->getHeaders();

                return count($from) === 1
                    && $from[0]->getAddress() === 'noreply@acme.com'
                    && $from[0]->getName() === 'Acme Corporation'
                    && count($replyTo) === 1
                    && $replyTo[0]->getAddress() === 'support@acme.com'
                    && count($bcc) === 1
                    && $bcc[0]->getAddress() === 'admin@acme.com'
                    && $headers->has('X-Tenant-ID')
                    && $headers->get('X-Tenant-ID')->getBody() === 'acme'
                    && $headers->has('X-Tenant-Name')
                    && $headers->get('X-Tenant-Name')->getBody() === 'Acme Corporation';
            }));

        // Act
        $this->mailer->send($email);
    }

    public function testSendTemplatedEmailWithTenantContext(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corporation');

        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->configurator->method('getFromAddress')->willReturn('noreply@acme.com');
        $this->configurator->method('getSenderName')->willReturn('Acme Corporation');
        $this->configurator->method('getLogoUrl')->willReturn('https://acme.com/logo.png');
        $this->configurator->method('getPrimaryColor')->willReturn('#ff6b35');
        $this->configurator->method('getWebsiteUrl')->willReturn('https://acme.com');

        // Mock template resolution
        $this->twig->expects($this->once())
            ->method('getLoader->exists')
            ->with('emails/tenant/acme/welcome.html.twig')
            ->willReturn(false);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'emails/welcome.html.twig',
                $this->callback(function (array $context) {
                    return isset($context['tenant'])
                        && $context['tenant']['slug'] === 'acme'
                        && $context['tenant']['name'] === 'Acme Corporation'
                        && $context['tenant']['logoUrl'] === 'https://acme.com/logo.png'
                        && $context['tenant']['primaryColor'] === '#ff6b35'
                        && $context['tenant']['websiteUrl'] === 'https://acme.com'
                        && isset($context['user'])
                        && $context['user']['name'] === 'John Doe';
                })
            )
            ->willReturn('<html><body>Welcome John!</body></html>');

        $this->innerMailer->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Email::class));

        // Act
        $this->mailer->sendTemplatedEmail(
            'user@example.com',
            'Welcome!',
            'emails/welcome.html.twig',
            ['user' => ['name' => 'John Doe']]
        );
    }

    public function testSendTemplatedEmailWithFromOverride(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corporation');

        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $this->configurator->method('getSenderName')->willReturn('Acme Corporation');

        $this->twig->expects($this->once())
            ->method('getLoader->exists')
            ->willReturn(false);

        $this->twig->expects($this->once())
            ->method('render')
            ->willReturn('<html><body>System notification</body></html>');

        $this->innerMailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) {
                $from = $email->getFrom();
                return count($from) === 1 && $from[0]->getAddress() === 'system@example.com';
            }));

        // Act
        $this->mailer->sendTemplatedEmail(
            'user@example.com',
            'System Notification',
            'emails/system.html.twig',
            [],
            'system@example.com'
        );
    }

    public function testSendTemplatedEmailThrowsExceptionWhenTwigNotAvailable(): void
    {
        // Arrange
        $mailer = new TenantAwareMailer(
            $this->innerMailer,
            $this->configurator,
            $this->tenantContext,
            null // No Twig available
        );

        // Expect
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Twig is required for templated emails');

        // Act
        $mailer->sendTemplatedEmail(
            'user@example.com',
            'Test',
            'template.html.twig'
        );
    }

    public function testSendEmailWithoutTenantContext(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);
        $this->configurator->method('getFromAddress')->willReturn('noreply@example.com');
        $this->configurator->method('getSenderName')->willReturn('Default App');

        $email = new Email();
        $email->to('user@example.com')
              ->subject('Test Email')
              ->text('Test content');

        $this->innerMailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $sentEmail) {
                $from = $sentEmail->getFrom();
                $headers = $sentEmail->getHeaders();

                return count($from) === 1
                    && $from[0]->getAddress() === 'noreply@example.com'
                    && $from[0]->getName() === 'Default App'
                    && !$headers->has('X-Tenant-ID')
                    && !$headers->has('X-Tenant-Name');
            }));

        // Act
        $this->mailer->send($email);
    }
}