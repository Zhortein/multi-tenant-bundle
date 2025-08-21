<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;
use Zhortein\MultiTenantBundle\Observability\Event\TenantHeaderRejectedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;

/**
 * Chain resolver that tries multiple tenant resolvers in order.
 *
 * This resolver iterates through configured resolvers in the specified order
 * and returns the first successful resolution. It supports strict mode for
 * error handling and provides comprehensive logging and metrics.
 */
final class ChainTenantResolver implements TenantResolverInterface
{
    /**
     * @param array<string, TenantResolverInterface> $resolvers
     * @param array<string>                          $order
     * @param array<string>                          $headerAllowList
     */
    public function __construct(
        private readonly array $resolvers,
        private readonly array $order,
        private readonly bool $strict = true,
        private readonly array $headerAllowList = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function resolveTenant(Request $request): ?TenantInterface
    {
        $results = [];
        $diagnostics = [
            'resolvers_tried' => [],
            'resolvers_skipped' => [],
            'header_allow_list' => $this->headerAllowList,
            'strict_mode' => $this->strict,
        ];

        foreach ($this->order as $resolverName) {
            if (!isset($this->resolvers[$resolverName])) {
                $diagnostics['resolvers_skipped'][] = [
                    'name' => $resolverName,
                    'reason' => 'resolver_not_found',
                ];
                $this->logger?->warning('Resolver not found in chain', [
                    'resolver' => $resolverName,
                    'available_resolvers' => array_keys($this->resolvers),
                ]);
                continue;
            }

            $resolver = $this->resolvers[$resolverName];

            // Apply header allow-list filtering for header resolvers
            if ($resolver instanceof HeaderTenantResolver && !$this->isHeaderAllowed($resolver->getHeaderName())) {
                $diagnostics['resolvers_skipped'][] = [
                    'name' => $resolverName,
                    'reason' => 'header_not_allowed',
                    'header_name' => $resolver->getHeaderName(),
                ];
                $this->logger?->debug('Header resolver skipped due to allow-list', [
                    'resolver' => $resolverName,
                    'header_name' => $resolver->getHeaderName(),
                    'allow_list' => $this->headerAllowList,
                ]);

                // Dispatch header rejected event
                $this->eventDispatcher?->dispatch(
                    new TenantHeaderRejectedEvent($resolver->getHeaderName())
                );

                continue;
            }

            try {
                $tenant = $resolver->resolveTenant($request);

                $diagnostics['resolvers_tried'][] = [
                    'name' => $resolverName,
                    'result' => $tenant ? $tenant->getSlug() : null,
                    'class' => $resolver::class,
                ];

                if (null !== $tenant) {
                    $results[$resolverName] = $tenant;

                    $this->logger?->info('Tenant resolved by chain resolver', [
                        'resolver' => $resolverName,
                        'tenant_slug' => $tenant->getSlug(),
                        'tenant_id' => $tenant->getId(),
                        'position_in_chain' => array_search($resolverName, $this->order, true),
                    ]);

                    // Dispatch tenant resolved event
                    $this->eventDispatcher?->dispatch(
                        new TenantResolvedEvent($resolverName, (string) $tenant->getId())
                    );

                    // In non-strict mode, return first match
                    if (!$this->strict) {
                        return $tenant;
                    }
                } else {
                    // Dispatch tenant resolution failed event
                    $this->eventDispatcher?->dispatch(
                        new TenantResolutionFailedEvent(
                            $resolverName,
                            'no_tenant_found',
                            ['request_uri' => $request->getRequestUri()]
                        )
                    );
                }
            } catch (\Throwable $e) {
                $diagnostics['resolvers_tried'][] = [
                    'name' => $resolverName,
                    'result' => null,
                    'error' => $e->getMessage(),
                    'class' => $resolver::class,
                ];

                $this->logger?->warning('Resolver threw exception', [
                    'resolver' => $resolverName,
                    'exception' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);

                // Dispatch tenant resolution failed event
                $this->eventDispatcher?->dispatch(
                    new TenantResolutionFailedEvent(
                        $resolverName,
                        'exception_thrown',
                        [
                            'exception_message' => $e->getMessage(),
                            'exception_class' => $e::class,
                        ]
                    )
                );

                if ($this->strict) {
                    throw new TenantResolutionException(sprintf('Resolver "%s" failed: %s', $resolverName, $e->getMessage()), $diagnostics, 0, $e);
                }
            }
        }

        // Handle results in strict mode
        if ($this->strict) {
            return $this->handleStrictModeResults($results, $diagnostics);
        }

        // Non-strict mode: no tenant found
        $this->logger?->debug('No tenant resolved by chain', [
            'resolvers_tried' => count($diagnostics['resolvers_tried']),
            'resolvers_skipped' => count($diagnostics['resolvers_skipped']),
        ]);

        return null;
    }

    /**
     * Handles results in strict mode, checking for ambiguity.
     *
     * @param array<string, TenantInterface> $results
     * @param array<string, mixed>           $diagnostics
     */
    private function handleStrictModeResults(array $results, array $diagnostics): TenantInterface
    {
        if (empty($results)) {
            throw new TenantResolutionException('No tenant could be resolved by any resolver in the chain', $diagnostics);
        }

        // Check for ambiguous results (different tenants from different resolvers)
        $uniqueTenants = [];
        foreach ($results as $resolverName => $tenant) {
            $tenantKey = $tenant->getId().':'.$tenant->getSlug();
            if (!isset($uniqueTenants[$tenantKey])) {
                $uniqueTenants[$tenantKey] = [
                    'tenant' => $tenant,
                    'resolvers' => [],
                ];
            }
            $uniqueTenants[$tenantKey]['resolvers'][] = $resolverName;
        }

        if (count($uniqueTenants) > 1) {
            // Multiple different tenants found - ambiguous
            $conflictingResults = [];
            foreach ($uniqueTenants as $data) {
                $resolverName = $data['resolvers'][0]; // Use first resolver for this tenant
                $conflictingResults[$resolverName] = $data['tenant'];
            }

            throw new AmbiguousTenantResolutionException($conflictingResults, $diagnostics);
        }

        // All resolvers agree on the same tenant
        $tenantData = array_values($uniqueTenants)[0];
        $tenant = $tenantData['tenant'];

        $this->logger?->info('Tenant resolved in strict mode', [
            'tenant_slug' => $tenant->getSlug(),
            'tenant_id' => $tenant->getId(),
            'resolvers_agreed' => $tenantData['resolvers'],
            'total_resolvers' => count($results),
        ]);

        return $tenant;
    }

    /**
     * Checks if a header name is allowed by the allow-list.
     */
    private function isHeaderAllowed(string $headerName): bool
    {
        if (empty($this->headerAllowList)) {
            return true; // No restrictions if allow-list is empty
        }

        return \in_array($headerName, $this->headerAllowList, true);
    }

    /**
     * Gets the configured resolver order.
     *
     * @return array<string>
     */
    public function getOrder(): array
    {
        return $this->order;
    }

    /**
     * Gets the available resolvers.
     *
     * @return array<string, TenantResolverInterface>
     */
    public function getResolvers(): array
    {
        return $this->resolvers;
    }

    /**
     * Checks if strict mode is enabled.
     */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Gets the header allow-list.
     *
     * @return array<string>
     */
    public function getHeaderAllowList(): array
    {
        return $this->headerAllowList;
    }
}
