<?php

declare(strict_types=1);

namespace App\Tests;

use App\Message\UnclassifiedMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;

final class AllMessengerBusesProtectedTest extends KernelTestCase
{
    public function testDefaultAndSecondaryBusesRejectUnclassifiedMessages(): void
    {
        self::bootKernel();

        foreach (['messenger.bus.default', 'secondary.bus'] as $busId) {
            $bus = self::getContainer()->get($busId);
            self::assertInstanceOf(MessageBusInterface::class, $bus);

            try {
                $bus->dispatch(new UnclassifiedMessage());
                self::fail(sprintf('Bus "%s" accepted an unclassified message.', $busId));
            } catch (UnclassifiedMessageException $exception) {
                self::assertStringContainsString('must implement', $exception->getMessage());
            }
        }
    }
}
