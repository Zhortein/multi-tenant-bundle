<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\MessengerRoutingStrategy;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
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
        $this->tenantContext = $this->createStub(TenantContextInterface::class);
        $this->stack = $this->createStub(StackInterface::class);

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
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getId')->willReturn('123');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new ResolverTenantMessage();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
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
                    && '123' === $tenantStamp->getTenantId();
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
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('unknown');
        $tenant->method('getId')->willReturn('456');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new ResolverTenantMessage();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
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
                    && '456' === $tenantStamp->getTenantId();
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

        $message = new ResolverTenantMessage();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
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
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getId')->willReturn('123');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new ResolverTenantMessage();
        $existingTransportStamp = new TransportNamesStamp(['existing_transport']);
        $envelope = new Envelope($message, [$existingTransportStamp]);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
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
                    && '123' === $tenantStamp->getTenantId();
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

        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getId')->willReturn('123');

        $this->tenantContext->method('getTenant')->willReturn($tenant);

        $message = new ResolverTenantMessage();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
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

    public function testSymfonyRoutingDoesNotAddATransportStamp(): void
    {
        $resolver = new TenantMessengerTransportResolver(
            $this->tenantContext,
            ['acme' => 'tenant_specific'],
            'fallback',
            true,
            MessengerRoutingStrategy::SYMFONY_ROUTING,
        );
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getId')->willReturn('123');
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $envelope = new Envelope(new ResolverTenantMessage());
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (Envelope $handled): bool => null === $handled->last(TransportNamesStamp::class)
                && '123' === $handled->last(TenantStamp::class)?->getTenantId()), $this->stack)
            ->willReturn($envelope);

        self::assertSame($envelope, $resolver->handle($envelope, $this->stack));
    }

    public function testSymfonyRoutingKeepsAnExplicitTransportStampIntact(): void
    {
        $resolver = new TenantMessengerTransportResolver(
            $this->tenantContext,
            ['acme' => 'tenant_specific'],
            'fallback',
            true,
            MessengerRoutingStrategy::SYMFONY_ROUTING,
        );
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $tenant->method('getId')->willReturn('123');
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $stamp = new TransportNamesStamp(['explicit']);
        $envelope = new Envelope(new ResolverTenantMessage(), [$stamp]);
        $nextMiddleware = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->method('next')->willReturn($nextMiddleware);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (Envelope $handled): bool => $stamp === $handled->last(TransportNamesStamp::class)), $this->stack)
            ->willReturn($envelope);

        self::assertSame($envelope, $resolver->handle($envelope, $this->stack));
    }

    public static function globalEnvelopes(): iterable
    {
        foreach (MessengerRoutingStrategy::cases() as $strategy) {
            foreach ([false, true] as $explicitRoute) {
                $stamps = $explicitRoute ? [new TransportNamesStamp(['explicit'])] : [];
                $global = new Envelope(new ResolverGlobalMessage(), $stamps);
                yield $strategy->value.' direct '.(int) $explicitRoute => [$strategy, $global];
                yield $strategy->value.' wrapper '.(int) $explicitRoute => [$strategy, new Envelope(new RedispatchMessage($global, 'persistent'), $stamps)];
            }
        }
    }

    #[DataProvider('globalEnvelopes')]
    public function testGlobalEnvelopeIsUnchangedUnderAnActiveTenant(MessengerRoutingStrategy $strategy, Envelope $envelope): void
    {
        $tenant = $this->createStub(TenantInterface::class);
        $tenant->method('getId')->willReturn('123');
        $tenant->method('getSlug')->willReturn('acme');
        $this->tenantContext->method('getTenant')->willReturn($tenant);
        $resolver = new TenantMessengerTransportResolver($this->tenantContext, ['acme' => 'tenant_only'], 'fallback', true, $strategy);
        $next = $this->createMock(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class);
        $this->stack->method('next')->willReturn($next);
        $next->expects($this->once())->method('handle')->with($this->identicalTo($envelope), $this->stack)->willReturn($envelope);

        self::assertSame($envelope, $resolver->handle($envelope, $this->stack));
        self::assertNull($envelope->last(TenantStamp::class));
    }

    public static function invalidEnvelopes(): iterable
    {
        yield 'unclassified' => [new Envelope(new \stdClass()), UnclassifiedMessageException::class];
        yield 'double classification' => [new Envelope(new ResolverDoubleMessage()), UnclassifiedMessageException::class];
        yield 'unknown wrapper' => [new Envelope(new class {
            public object $message;
        }), UnclassifiedMessageException::class];
        yield 'global stamp' => [new Envelope(new ResolverGlobalMessage(), [new TenantStamp('123')]), TenantMismatchException::class];
        yield 'outer global stamp' => [new Envelope(new RedispatchMessage(new ResolverGlobalMessage(), 'persistent'), [new TenantStamp('123')]), TenantMismatchException::class];
        yield 'inner global stamp' => [new Envelope(new RedispatchMessage(new Envelope(new ResolverGlobalMessage(), [new TenantStamp('123')]), 'persistent')), TenantMismatchException::class];
    }

    #[DataProvider('invalidEnvelopes')]
    public function testInvalidClassificationNeverReachesTheNextMiddleware(Envelope $envelope, string $exception): void
    {
        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->never())->method('next');
        $this->expectException($exception);
        $this->resolver->handle($envelope, $stack);
    }
}

final class ResolverTenantMessage implements TenantAwareMessageInterface
{
}

final class ResolverGlobalMessage implements GlobalMessageInterface
{
}

final class ResolverDoubleMessage implements TenantAwareMessageInterface, GlobalMessageInterface
{
}
