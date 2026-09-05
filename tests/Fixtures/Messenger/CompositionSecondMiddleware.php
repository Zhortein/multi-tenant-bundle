<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class CompositionSecondMiddleware implements MiddlewareInterface
{
    public function __construct(private CompositionProbe $probe)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->probe->record('two.before', $envelope->getMessage());
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->probe->record('two.after', $envelope->getMessage());
        }
    }
}
