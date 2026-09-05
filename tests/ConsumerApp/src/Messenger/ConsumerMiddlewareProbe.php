<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class ConsumerMiddlewareProbe implements MiddlewareInterface
{
    /** @var list<array{string, class-string, string|null}> */
    public array $events = [];

    public function __construct(private readonly TenantContextInterface $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->record('before', $envelope->getMessage());
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->record('after', $envelope->getMessage());
        }
    }

    public function record(string $stage, object $message): void
    {
        $this->events[] = [$stage, $message::class, $this->context->getTenant()?->getSlug()];
    }
}
