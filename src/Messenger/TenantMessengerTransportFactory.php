<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Transport factory for tenant-aware messenger configuration.
 *
 * This factory creates messenger transports based on tenant-specific DSN settings,
 * with support for various transport types (sync, doctrine, redis, etc.).
 */
final class TenantMessengerTransportFactory implements TransportFactoryInterface
{
    /**
     * @param iterable<TransportFactoryInterface> $factories
     */
    public function __construct(
        private readonly TenantContextInterface $tenantContext, // @phpstan-ignore-line
        private readonly TenantMessengerConfigurator $messengerConfigurator,
        private readonly iterable $factories,
        private readonly ?string $fallbackDsn = 'sync://',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        // Get tenant-specific DSN or use fallback
        $tenantDsn = $this->messengerConfigurator->getTransportDsn($this->fallbackDsn ?? 'sync://');

        // Apply tenant-specific delay if configured
        $delay = $this->messengerConfigurator->getDelay();
        if ($delay > 0) {
            $options['delay'] = $delay;
        }

        try {
            // Find a factory that supports the tenant DSN
            foreach ($this->factories as $factory) {
                if ($factory->supports($tenantDsn, $options)) {
                    return $factory->createTransport($tenantDsn, $options, $serializer);
                }
            }
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to create tenant messenger transport', [
                'tenant_dsn' => $tenantDsn,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to sync transport if no factory supports the DSN
        foreach ($this->factories as $factory) {
            if ($factory->supports('sync://', $options)) {
                return $factory->createTransport('sync://', $options, $serializer);
            }
        }

        throw new \RuntimeException('No messenger transport factory available for tenant transport.');
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'tenant://');
    }
}
