<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger\Internal;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

/**
 * Exact application-message classification behind explicitly supported Symfony wrappers.
 *
 * @internal this is deliberately not an application extension point
 */
final readonly class MessageClassification
{
    public const MAX_REDISPATCH_DEPTH = 8;

    /**
     * @param list<TenantStamp> $tenantStamps
     */
    private function __construct(
        public bool $tenantAware,
        public array $tenantStamps,
        public int $redispatchDepth,
    ) {
    }

    public static function fromEnvelope(Envelope $envelope): self
    {
        /** @var \SplObjectStorage<object, null> $seen */
        $seen = new \SplObjectStorage();
        $tenantStamps = [];
        $redispatchDepth = 0;

        while (true) {
            self::remember($envelope, $seen);
            if (0 < $redispatchDepth && null !== $envelope->last(ReceivedStamp::class)) {
                throw new UnclassifiedMessageException('A RedispatchMessage cannot encapsulate an envelope that is already marked as received.');
            }
            foreach ($envelope->all(TenantStamp::class) as $tenantStamp) {
                $tenantStamps[] = $tenantStamp;
            }

            $message = $envelope->getMessage();
            self::remember($message, $seen);

            if (!$message instanceof RedispatchMessage) {
                $tenantAware = $message instanceof TenantAwareMessageInterface;
                $global = $message instanceof GlobalMessageInterface;
                if ($tenantAware === $global) {
                    throw new UnclassifiedMessageException($tenantAware ? 'A message cannot be both tenant-aware and global.' : 'A message must implement TenantAwareMessageInterface or GlobalMessageInterface, including when carried by RedispatchMessage.');
                }
                if ($global && [] !== $tenantStamps) {
                    throw new TenantMismatchException('A global message cannot carry a TenantStamp, including on a RedispatchMessage wrapper.');
                }

                return new self($tenantAware, $tenantStamps, $redispatchDepth);
            }

            if (self::MAX_REDISPATCH_DEPTH === $redispatchDepth) {
                throw new UnclassifiedMessageException(sprintf('RedispatchMessage nesting exceeds the supported depth of %d.', self::MAX_REDISPATCH_DEPTH));
            }

            // Serialized transport payloads can bypass the constructor and leave a public readonly property uninitialized.
            // @phpstan-ignore isset.property
            if (!isset($message->transportNames)) {
                throw new UnclassifiedMessageException('RedispatchMessage must expose a readable destination.');
            }
            self::validateRedispatchDestination($message->transportNames);

            // @phpstan-ignore isset.property
            if (!isset($message->envelope)) {
                throw new UnclassifiedMessageException('RedispatchMessage must contain a readable message or Envelope.');
            }

            $envelope = Envelope::wrap($message->envelope);
            ++$redispatchDepth;
        }
    }

    /**
     * @param \SplObjectStorage<object, null> $seen
     */
    private static function remember(object $object, \SplObjectStorage $seen): void
    {
        if ($seen->offsetExists($object)) {
            throw new UnclassifiedMessageException('RedispatchMessage contains a cyclic or otherwise repeated wrapper structure.');
        }

        $seen->offsetSet($object);
    }

    /**
     * @param string|array<array-key, string> $transportNames
     */
    private static function validateRedispatchDestination(array|string $transportNames): void
    {
        $transportNames = (array) $transportNames;

        if ([] === $transportNames) {
            throw new UnclassifiedMessageException('RedispatchMessage requires at least one explicit destination.');
        }

        foreach ($transportNames as $transportName) {
            self::validateTransportName($transportName);
        }
    }

    private static function validateTransportName(mixed $transportName): void
    {
        if (!is_string($transportName) || '' === trim($transportName)) {
            throw new UnclassifiedMessageException('RedispatchMessage destinations must be non-empty transport names.');
        }
    }
}
