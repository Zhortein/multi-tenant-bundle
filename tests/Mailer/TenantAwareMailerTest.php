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

                return 1 === count($from)
                    && 'noreply@acme.com' === $from[0]->getAddress()
                    && 'Acme Corporation' === $from[0]->getName()
                    && 1 === count($replyTo)
                    && 'support@acme.com' === $replyTo[0]->getAddress()
                    && 1 === count($bcc)
                    && 'admin@acme.com' === $bcc[0]->getAddress()
                    && $headers->has('X-Tenant-ID')
                    && 'acme' === $headers->get('X-Tenant-ID')->getBody()
                    && $headers->has('X-Tenant-Name')
                    && 'Acme Corporation' === $headers->get('X-Tenant-Name')->getBody();
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
                        && 'acme' === $context['tenant']['slug']
                        && 'Acme Corporation' === $context['tenant']['name']
                        && 'https://acme.com/logo.png' === $context['tenant']['logoUrl']
                        && '#ff6b35' === $context['tenant']['primaryColor']
                        && 'https://acme.com' === $context['tenant']['websiteUrl']
                        && isset($context['user'])
                        && 'John Doe' === $context['user']['name'];
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

                return 1 === count($from) && 'system@example.com' === $from[0]->getAddress();
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

                return 1 === count($from)
                    && 'noreply@example.com' === $from[0]->getAddress()
                    && 'Default App' === $from[0]->getName()
                    && !$headers->has('X-Tenant-ID')
                    && !$headers->has('X-Tenant-Name');
            }));

        // Act
        $this->mailer->send($email);
    }
}
