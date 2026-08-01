<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Mailer;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Selects the first Symfony transport factory supporting a fallback DSN.
 *
 * @internal
 */
final readonly class TenantMailerFallbackTransportFactory implements TransportFactoryInterface
{
    /**
     * @param iterable<TransportFactoryInterface> $factories
     */
    public function __construct(private iterable $factories)
    {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($dsn)) {
                return $factory->create($dsn);
            }
        }

        throw new UnsupportedSchemeException($dsn);
    }

    public function supports(Dsn $dsn): bool
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($dsn)) {
                return true;
            }
        }

        return false;
    }
}
