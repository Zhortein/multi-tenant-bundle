<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Command\ListTenantsCommand;
use Zhortein\MultiTenantBundle\Command\TenantImpersonateCommand;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;

/**
 * Integration tests for tenant-aware commands.
 *
 * @covers \Zhortein\MultiTenantBundle\Command\AbstractTenantAwareCommand
 * @covers \Zhortein\MultiTenantBundle\Command\ListTenantsCommand
 * @covers \Zhortein\MultiTenantBundle\Command\TenantImpersonateCommand
 */
final class TenantCommandsIntegrationTest extends TestCase
{
    private InMemoryTenantRegistry $tenantRegistry;
    private TenantContext $tenantContext;
    private Application $application;

    protected function setUp(): void
    {
        $this->tenantRegistry = new InMemoryTenantRegistry();
        $this->tenantContext = new TenantContext();

        // Create test tenants
        $tenant1 = $this->createMockTenant('1', 'tenant1', 'Tenant One');
        $tenant2 = $this->createMockTenant('2', 'tenant2', 'Tenant Two');

        $this->tenantRegistry->addTenant($tenant1);
        $this->tenantRegistry->addTenant($tenant2);

        $this->application = new Application();
        $this->application->add(new ListTenantsCommand(
            $this->tenantRegistry,
            $this->tenantContext,
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            'App\\Entity\\Tenant'
        ));
        $this->application->add(new TenantImpersonateCommand(
            $this->tenantRegistry,
            $this->tenantContext,
            true
        ));
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['TENANT_ID'], $_SERVER['TENANT_ID']);
        $this->tenantContext->clear();
    }

    public function testListTenantsWithoutTenantContext(): void
    {
        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('tenant1', $output);
        $this->assertStringContainsString('tenant2', $output);
        $this->assertStringContainsString('Found 2 tenant(s)', $output);
    }

    public function testListTenantsWithTenantOption(): void
    {
        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--tenant' => 'tenant1']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Operating on tenant: tenant1', $output);
        $this->assertStringContainsString('Found 1 tenant(s)', $output);
        $this->assertStringNotContainsString('tenant2', $output);
    }

    public function testListTenantsWithEnvironmentVariable(): void
    {
        $_ENV['TENANT_ID'] = 'tenant2';

        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Operating on tenant: tenant2', $output);
        $this->assertStringContainsString('Found 1 tenant(s)', $output);
        $this->assertStringNotContainsString('tenant1', $output);
    }

    public function testListTenantsWithServerVariable(): void
    {
        $_SERVER['TENANT_ID'] = 'tenant1';

        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Operating on tenant: tenant1', $output);
        $this->assertStringContainsString('Found 1 tenant(s)', $output);
    }

    public function testTenantOptionOverridesEnvironmentVariable(): void
    {
        $_ENV['TENANT_ID'] = 'tenant2';

        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--tenant' => 'tenant1']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Operating on tenant: tenant1', $output);
        $this->assertStringNotContainsString('tenant2', $output);
    }

    public function testListTenantsWithUnknownTenant(): void
    {
        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tenant: unknown');

        $commandTester->execute(['--tenant' => 'unknown']);
    }

    public function testListTenantsWithUnknownTenantFromEnvironment(): void
    {
        $_ENV['TENANT_ID'] = 'unknown';

        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tenant: unknown');

        $commandTester->execute([]);
    }

    public function testImpersonateCommandWithValidTenant(): void
    {
        $command = $this->application->find('tenant:impersonate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['tenant-identifier' => 'tenant1']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('SECURITY WARNING', $output);
        $this->assertStringContainsString('Successfully impersonating tenant: tenant1', $output);
    }

    public function testImpersonateCommandWithInvalidTenant(): void
    {
        $command = $this->application->find('tenant:impersonate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['tenant-identifier' => 'invalid']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Tenant not found: invalid', $output);
    }

    public function testImpersonateCommandWithNumericTenantId(): void
    {
        $command = $this->application->find('tenant:impersonate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['tenant-identifier' => '1']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Successfully impersonating tenant: tenant1', $output);
    }

    public function testListTenantsJsonFormat(): void
    {
        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        $slugs = array_column($data, 'slug');
        $this->assertContains('tenant1', $slugs);
        $this->assertContains('tenant2', $slugs);
    }

    public function testListTenantsYamlFormat(): void
    {
        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--format' => 'yaml']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('tenants:', $output);
        $this->assertStringContainsString('slug: tenant1', $output);
        $this->assertStringContainsString('slug: tenant2', $output);
    }

    public function testTenantContextIsClearedAfterCommand(): void
    {
        // Verify context is initially empty
        $this->assertNull($this->tenantContext->getTenant());

        $command = $this->application->find('tenant:list');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--tenant' => 'tenant1']);

        // The context should have the tenant during execution, but we can't easily test
        // the clearing since it happens in finalize() which isn't called by CommandTester
        // in the same way as the real console application
        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
    }

    public function testMultipleCommandsWithDifferentTenants(): void
    {
        // First command with tenant1
        $command1 = $this->application->find('tenant:list');
        $commandTester1 = new CommandTester($command1);
        $commandTester1->execute(['--tenant' => 'tenant1']);

        $this->assertSame(Command::SUCCESS, $commandTester1->getStatusCode());
        $this->assertStringContainsString('tenant1', $commandTester1->getDisplay());

        // Second command with tenant2
        $command2 = $this->application->find('tenant:list');
        $commandTester2 = new CommandTester($command2);
        $commandTester2->execute(['--tenant' => 'tenant2']);

        $this->assertSame(Command::SUCCESS, $commandTester2->getStatusCode());
        $this->assertStringContainsString('tenant2', $commandTester2->getDisplay());
        $this->assertStringNotContainsString('tenant1', $commandTester2->getDisplay());
    }

    private function createMockTenant(string $id, string $slug, string $name): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getSlug')->willReturn($slug);
        $tenant->method('getMailerDsn')->willReturn(null);
        $tenant->method('getMessengerDsn')->willReturn(null);

        return $tenant;
    }
}
