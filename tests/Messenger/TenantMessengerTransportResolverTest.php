<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

/**
 * @covers \Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver
 */
class TenantMessengerTransportResolverTest extends TestCase
{
    private TenantContextInterface $tenantContext;
    private TenantMessengerTransportResolver $resolver;
    private StackInterface $stack;

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContextInterface::class);
        $this->stack = $this->createMock(StackInterface::class);

        $this->resolver = new TenantMessengerTransportResolver(
            $this->tenantContext,
            [
                'acme' => 'acme_transport',
                'bio' => 'bio_transport',
            ],
            'default_transport',
            true
        );
    }

    public function testHandleWithTenantTransportMapping(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corporation');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(StackInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $envelope) {
                // Check that TransportNamesStamp was added
                $transportStamp = $envelope->last(TransportNamesStamp::class);
                if (!$transportStamp || !in_array('acme_transport', $transportStamp->getTransportNames())) {
                    return false;
                }

                // Check that TenantStamp was added
                $tenantStamp = $envelope->last(TenantStamp::class);
                return $tenantStamp
                    && $tenantStamp->getTenantSlug() === 'acme'
                    && $tenantStamp->getTenantName() === 'Acme Corporation';
            }), $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->resolver->handle($envelope, $this->stack);

        // Assert
        $this->assertInstanceOf(Envelope::class, $result);
    }

    public function testHandleWithDefaultTransport(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('unknown');
        $tenant->method('getName')->willReturn('Unknown Tenant');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(StackInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $envelope) {
                // Check that default transport was used
                $transportStamp = $envelope->last(TransportNamesStamp::class);
                if (!$transportStamp || !in_array('default_transport', $transportStamp->getTransportNames())) {
                    return false;
                }

                // Check that TenantStamp was still added
                $tenantStamp = $envelope->last(TenantStamp::class);
                return $tenantStamp
                    && $tenantStamp->getTenantSlug() === 'unknown'
                    && $tenantStamp->getTenantName() === 'Unknown Tenant';
            }), $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->resolver->handle($envelope, $this->stack);

        // Assert
        $this->assertInstanceOf(Envelope::class, $result);
    }

    public function testHandleWithoutTenant(): void
    {
        // Arrange
        $this->tenantContext->method('getTenant')->willReturn(null);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(StackInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $envelope) {
                // Check that no transport stamp was added
                $transportStamp = $envelope->last(TransportNamesStamp::class);
                if ($transportStamp) {
                    return false;
                }

                // Check that no tenant stamp was added
                $tenantStamp = $envelope->last(TenantStamp::class);
                return !$tenantStamp;
            }), $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->resolver->handle($envelope, $this->stack);

        // Assert
        $this->assertInstanceOf(Envelope::class, $result);
    }

    public function testHandleWithExistingTransportStamp(): void
    {
        // Arrange
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corporation');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $existingTransportStamp = new TransportNamesStamp(['existing_transport']);
        $envelope = new Envelope($message, [$existingTransportStamp]);

        $nextMiddleware = $this->createMock(StackInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $envelope) {
                // Check that existing transport stamp was preserved
                $transportStamp = $envelope->last(TransportNamesStamp::class);
                if (!$transportStamp || !in_array('existing_transport', $transportStamp->getTransportNames())) {
                    return false;
                }

                // Check that tenant stamp was still added
                $tenantStamp = $envelope->last(TenantStamp::class);
                return $tenantStamp
                    && $tenantStamp->getTenantSlug() === 'acme'
                    && $tenantStamp->getTenantName() === 'Acme Corporation';
            }), $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $this->resolver->handle($envelope, $this->stack);

        // Assert
        $this->assertInstanceOf(Envelope::class, $result);
    }

    public function testHandleWithTenantHeadersDisabled(): void
    {
        // Arrange
        $resolver = new TenantMessengerTransportResolver(
            $this->tenantContext,
            ['acme' => 'acme_transport'],
            'default_transport',
            false // Tenant headers disabled
        );

        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getName')->willReturn('Acme Corporation');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(StackInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);

        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $envelope) {
                // Check that transport stamp was added
                $transportStamp = $envelope->last(TransportNamesStamp::class);
                if (!$transportStamp || !in_array('acme_transport', $transportStamp->getTransportNames())) {
                    return false;
                }

                // Check that NO tenant stamp was added (headers disabled)
                $tenantStamp = $envelope->last(TenantStamp::class);
                return !$tenantStamp;
            }), $this->stack)
            ->willReturn($envelope);

        // Act
        $result = $resolver->handle($envelope, $this->stack);

        // Assert
        $this->assertInstanceOf(Envelope::class, $result);
    }
}