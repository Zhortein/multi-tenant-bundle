<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Observability\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when tenant resolution fails.
 *
 * This event is fired when a tenant resolver fails to identify
 * a tenant from the current request context.
 */
final class TenantResolutionFailedEvent extends Event
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $resolver,
        private readonly string $reason,
        private readonly array $context = [],
    ) {
    }

    /**
     * Gets the name of the resolver that failed.
     */
    public function getResolver(): string
    {
        return $this->resolver;
    }

    /**
     * Gets the reason for the failure.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Gets additional context information about the failure.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}