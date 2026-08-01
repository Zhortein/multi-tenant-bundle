<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerFallbackTransportFactory;

final class TenantMailerFallbackTransportFactoryTest extends TestCase
{
    public function testDelegatesToTheFirstSupportingFactory(): void
    {
        $dsn = new Dsn('smtp', 'localhost');
        $unsupportedFactory = $this->createMock(TransportFactoryInterface::class);
        $unsupportedFactory->method('supports')->with($dsn)->willReturn(false);
        $unsupportedFactory->expects(self::never())->method('create');

        $transport = $this->createMock(TransportInterface::class);
        $supportedFactory = $this->createMock(TransportFactoryInterface::class);
        $supportedFactory->method('supports')->with($dsn)->willReturn(true);
        $supportedFactory->expects(self::once())->method('create')->with($dsn)->willReturn($transport);

        $factory = new TenantMailerFallbackTransportFactory([$unsupportedFactory, $supportedFactory]);

        self::assertTrue($factory->supports($dsn));
        self::assertSame($transport, $factory->create($dsn));
    }

    public function testRejectsAnUnsupportedScheme(): void
    {
        $dsn = new Dsn('unsupported', 'localhost');
        $delegate = $this->createMock(TransportFactoryInterface::class);
        $delegate->expects(self::once())->method('supports')->with($dsn)->willReturn(false);

        $factory = new TenantMailerFallbackTransportFactory([$delegate]);

        $this->expectException(UnsupportedSchemeException::class);
        $factory->create($dsn);
    }
}
