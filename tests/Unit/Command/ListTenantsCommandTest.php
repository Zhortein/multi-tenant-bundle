<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Command\ListTenantsCommand;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

/**
 * @covers \Zhortein\MultiTenantBundle\Command\ListTenantsCommand
 */
final class ListTenantsCommandTest extends TestCase
{
    private ListTenantsCommand $command;
    private EntityManagerInterface $entityManager;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with('App\\Entity\\Tenant')
            ->willReturn($this->repository);

        $this->command = new ListTenantsCommand($this->entityManager, 'App\\Entity\\Tenant');
    }

    public function testExecuteWithNoTenants(): void
    {
        $this->repository->method('findAll')->willReturn([]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No tenants found.', $commandTester->getDisplay());
    }

    public function testExecuteWithTenants(): void
    {
        $tenant1 = $this->createMock(TenantInterface::class);
        $tenant1->method('getId')->willReturn('1');
        $tenant1->method('getSlug')->willReturn('tenant-1');
        $tenant1->method('getMailerDsn')->willReturn('smtp://localhost');
        $tenant1->method('getMessengerDsn')->willReturn('redis://localhost');

        $tenant2 = $this->createMock(TenantInterface::class);
        $tenant2->method('getId')->willReturn('2');
        $tenant2->method('getSlug')->willReturn('tenant-2');
        $tenant2->method('getMailerDsn')->willReturn(null);
        $tenant2->method('getMessengerDsn')->willReturn(null);

        // Mock getName method if it exists
        if (method_exists($tenant1, 'getName')) {
            $tenant1->method('getName')->willReturn('Tenant One');
            $tenant2->method('getName')->willReturn('Tenant Two');
        }

        $this->repository->method('findAll')->willReturn([$tenant1, $tenant2]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('tenant-1', $output);
        $this->assertStringContainsString('tenant-2', $output);
        $this->assertStringContainsString('smtp://localhost', $output);
        $this->assertStringContainsString('redis://localhost', $output);
        $this->assertStringContainsString('N/A', $output); // For null values
        $this->assertStringContainsString('Found 2 tenant(s).', $output);
    }

    public function testCommandConfiguration(): void
    {
        $this->assertSame('tenant:list', $this->command->getName());
        $this->assertSame('Lists all tenants in the system', $this->command->getDescription());
    }
}
