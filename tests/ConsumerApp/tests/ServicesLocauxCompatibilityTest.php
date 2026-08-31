<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\GlobalRecord;
use App\Entity\Tenant;
use App\Entity\TenantRecord;
use App\Message\GlobalMessage;
use App\Message\TenantMessage;
use App\Message\UnclassifiedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantStampException;
use Zhortein\MultiTenantBundle\Exception\TenantMismatchException;
use Zhortein\MultiTenantBundle\Exception\UnclassifiedMessageException;
use Zhortein\MultiTenantBundle\Exception\UnknownTenantException;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;

final class ServicesLocauxCompatibilityTest extends KernelTestCase
{
    public function testExactConsumerGraphPreservesFailClosedDoctrineAndMessengerContracts(): void
    {
        if ('1' !== getenv('EXACT_CONSUMER')) {
            self::markTestSkipped('The exact PostgreSQL consumer graph is exercised by dedicated CI jobs.');
        }

        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $context = $container->get(TenantContextInterface::class);

        $tenantA = new Tenant('a', 'a');
        $tenantB = new Tenant('b', 'b');
        $entityManager->persist($tenantA);
        $entityManager->persist($tenantB);
        $entityManager->persist(new GlobalRecord('global'));
        $entityManager->flush();
        self::assertCount(1, $entityManager->getRepository(GlobalRecord::class)->findAll());

        try {
            $entityManager->getRepository(TenantRecord::class)->findAll();
            self::fail('Tenant-aware reads without context must fail closed.');
        } catch (MissingTenantContextException) {
        }

        $context->setTenant($tenantA);
        $entityManager->persist(new TenantRecord('visible-a'));
        $entityManager->flush();
        $context->setTenant($tenantB);
        $entityManager->persist(new TenantRecord('visible-b'));
        $entityManager->flush();
        $context->setTenant($tenantA);
        self::assertSame(['visible-a'], array_map(static fn (TenantRecord $record): string => $record->getName(), $entityManager->getRepository(TenantRecord::class)->findAll()));

        $contradictory = new TenantRecord('forbidden');
        $contradictory->setTenant($tenantB);
        try {
            $entityManager->persist($contradictory);
            self::fail('A contradictory tenant-aware write must fail closed.');
        } catch (TenantMismatchException) {
        }
        $entityManager->clear();
        $context->clear();

        $sending = $container->get(TenantSendingMiddleware::class);
        $worker = $container->get(TenantWorkerMiddleware::class);
        $stack = $this->stackThatAcceptsMessages();

        $this->assertRejected(UnclassifiedMessageException::class, fn () => $sending->handle(new Envelope(new UnclassifiedMessage()), $stack));
        $this->assertRejected(MissingTenantContextException::class, fn () => $sending->handle(new Envelope(new TenantMessage()), $stack));
        $this->assertRejected(MissingTenantStampException::class, fn () => $worker->handle($this->received(new TenantMessage()), $stack));
        $this->assertRejected(UnknownTenantException::class, fn () => $worker->handle($this->received(new TenantMessage(), new TenantStamp('unknown')), $stack));
        $this->assertRejected(TenantMismatchException::class, fn () => $worker->handle($this->received(new GlobalMessage(), new TenantStamp('a')), $stack));
        self::assertInstanceOf(Envelope::class, $worker->handle($this->received(new GlobalMessage()), $stack));
    }

    private function stackThatAcceptsMessages(): StackInterface
    {
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);

        return $stack;
    }

    private function received(object $message, TenantStamp ...$stamps): Envelope
    {
        return new Envelope($message, [...$stamps, new ReceivedStamp('async')]);
    }

    private function assertRejected(string $exception, callable $operation): void
    {
        try {
            $operation();
            self::fail(sprintf('Expected %s.', $exception));
        } catch (\Throwable $failure) {
            self::assertInstanceOf($exception, $failure);
        }
    }
}
