<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScopeInterface;

final readonly class LifecycleController
{
    public function __construct(
        private TenantContextInterface $context,
        private HttpKernelInterface $httpKernel,
        private GlobalDoctrineScopeInterface $globalScope,
    ) {
    }

    public function exception(): never
    {
        if (null === $this->context->getTenant()) {
            throw new \LogicException('The controller must run with a tenant.');
        }

        throw new \RuntimeException('controlled controller failure');
    }

    public function redirect(): RedirectResponse
    {
        if (null === $this->context->getTenant()) {
            throw new \LogicException('The controller must run with a tenant.');
        }

        return new RedirectResponse('/test/products');
    }

    public function stream(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            $tenant = $this->context->getTenant();
            echo null === $tenant ? 'none' : $tenant->getSlug();
        });
    }

    public function context(): JsonResponse
    {
        return new JsonResponse(['tenant' => $this->context->getTenant()?->getSlug()]);
    }

    public function subRequest(): JsonResponse
    {
        $before = $this->context->getTenant()?->getSlug();
        $response = $this->httpKernel->handle(
            Request::create('/test/lifecycle/context'),
            HttpKernelInterface::SUB_REQUEST,
            false,
        );

        return new JsonResponse([
            'before' => $before,
            'sub_request' => $response->getContent(),
            'after' => $this->context->getTenant()?->getSlug(),
        ]);
    }

    public function global(): JsonResponse
    {
        return $this->globalScope->run(fn (): JsonResponse => new JsonResponse([
            'global' => true,
            'tenant' => $this->context->getTenant()?->getSlug(),
        ]));
    }
}
