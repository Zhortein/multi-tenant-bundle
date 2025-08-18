<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;

/**
 * Handles tenant resolution exceptions and converts them to appropriate HTTP responses.
 *
 * In production mode, converts exceptions to 400 Bad Request responses.
 * In development mode, includes diagnostic information in the response.
 */
final class TenantResolutionExceptionListener
{
    public function __construct(
        private readonly string $environment,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof TenantResolutionException) {
            return;
        }

        $this->logger?->error('Tenant resolution failed', [
            'exception' => $exception->getMessage(),
            'diagnostics' => $exception->getDiagnostics(),
            'request_uri' => $event->getRequest()->getRequestUri(),
            'request_method' => $event->getRequest()->getMethod(),
        ]);

        $response = $this->createResponse($exception);
        $event->setResponse($response);
    }

    private function createResponse(TenantResolutionException $exception): Response
    {
        $isDev = \in_array($this->environment, ['dev', 'test'], true);

        if ($exception instanceof AmbiguousTenantResolutionException) {
            $message = 'Multiple tenant resolution strategies returned different results';
            $statusCode = Response::HTTP_BAD_REQUEST;
        } else {
            $message = 'Unable to resolve tenant from request';
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        $data = [
            'error' => $message,
            'code' => $statusCode,
        ];

        // Include diagnostics in development mode
        if ($isDev) {
            $data['diagnostics'] = $exception->getDiagnostics();
            $data['exception_message'] = $exception->getMessage();

            if ($exception instanceof AmbiguousTenantResolutionException) {
                $data['type'] = 'ambiguous_resolution';
            } else {
                $data['type'] = 'resolution_failed';
            }
        }

        return new JsonResponse($data, $statusCode);
    }
}
