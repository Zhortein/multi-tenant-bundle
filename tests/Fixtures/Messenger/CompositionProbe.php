<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class CompositionProbe implements MiddlewareInterface
{
    public static ?self $active = null;
    public array $events = [];
    public MessageBusInterface $bus;
    public MessageBusInterface $otherBus;

    public function __construct(public readonly TenantContextInterface $context)
    {
    }

    public static function validateMessage(object $message, ExecutionContextInterface $validation): void
    {
        self::$active?->record('validation', $message);
    }

    public function record(string $stage, object $message): void
    {
        $this->events[] = [$stage, $message->action ?? 'default', $this->context->getTenant()?->getId()];
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $this->record('one.before', $message);
        try {
            if ('before_failure' === ($message->action ?? '')) {
                throw new \RuntimeException('before failure');
            }
            $result = $stack->next()->handle($envelope, $stack);
            if ('after_failure' === ($message->action ?? '')) {
                throw new \RuntimeException('after failure');
            }

            return $result;
        } finally {
            $this->record('one.after', $message);
        }
    }

    public function __invoke(object $message): void
    {
        $this->record('handler', $message);
        $action = $message->action ?? '';
        if (in_array($action, ['delayed', 'delayed_failure', 'delayed_then_failure'], true)) {
            $this->bus->dispatch(new CompositionTenantMessage('delayed_failure' === $action ? 'failure' : 'child'), [new DispatchAfterCurrentBusStamp()]);
        }
        if (in_array($action, ['nested', 'nested_other_bus'], true)) {
            ('nested' === $action ? $this->bus : $this->otherBus)->dispatch(new CompositionTenantMessage('child'));
            $this->record('parent.resumed', $message);
        }
        if (in_array($action, ['failure', 'delayed_then_failure'], true)) {
            throw new \RuntimeException('handler failure');
        }
    }
}
