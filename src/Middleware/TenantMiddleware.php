<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;

/**
 * HTTP middleware that resolves tenant context from incoming requests.
 *
 * This middleware can be used as an alternative to event listeners
 * for tenant resolution, providing more explicit control over the
 * tenant resolution process.
 */
final readonly class TenantMiddleware implements HttpKernelInterface
{
    public function __construct(
        private HttpKernelInterface $app,
        private TenantContextInterface $tenantContext,
        private TenantResolverInterface $tenantResolver,
        private bool $requireTenant = false,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        // Only process main requests
        if (self::MAIN_REQUEST === $type) {
            $this->resolveTenantContext($request);
        }

        return $this->app->handle($request, $type, $catch);
    }

    /**
     * Resolves and sets the tenant context from the request.
     *
     * @param Request $request The HTTP request
     *
     * @throws TenantNotFoundException When tenant is required but not found
     */
    private function resolveTenantContext(Request $request): void
    {
        try {
            $tenant = $this->tenantResolver->resolve($request);

            if (null !== $tenant) {
                $this->tenantContext->setTenant($tenant);

                $this->logger?->info('Tenant resolved via middleware', [
                    'tenant_id' => $tenant->getId(),
                    'tenant_slug' => $tenant->getSlug(),
                    'request_uri' => $request->getRequestUri(),
                ]);
            } elseif ($this->requireTenant) {
                throw new TenantNotFoundException('Tenant is required but could not be resolved from request');
            } else {
                $this->logger?->debug('No tenant resolved from request', [
                    'request_uri' => $request->getRequestUri(),
                    'host' => $request->getHost(),
                ]);
            }
        } catch (TenantNotFoundException $exception) {
            $this->logger?->warning('Tenant resolution failed', [
                'exception' => $exception->getMessage(),
                'request_uri' => $request->getRequestUri(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger?->error('Unexpected error during tenant resolution', [
                'exception' => $exception->getMessage(),
                'request_uri' => $request->getRequestUri(),
            ]);

            if ($this->requireTenant) {
                throw new TenantNotFoundException('Failed to resolve tenant due to unexpected error', previous: $exception);
            }
        }
    }
}
