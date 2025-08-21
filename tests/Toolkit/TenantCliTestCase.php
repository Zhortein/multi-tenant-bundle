<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Toolkit;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Base test case for CLI/console tests with tenant context support.
 *
 * This class extends KernelTestCase and provides utilities for:
 * - Testing console commands with tenant context
 * - Managing tenant context during command execution
 * - Testing tenant-aware command options and arguments
 */
abstract class TenantCliTestCase extends KernelTestCase
{
    use WithTenantTrait;

    protected ?Application $application = null;
    protected ?EntityManagerInterface $entityManager = null;
    protected ?TenantContextInterface $tenantContext = null;
    protected ?TenantRegistryInterface $tenantRegistry = null;
    protected ?TestData $testData = null;

    protected function setUp(): void
    {
        if (!$this->isKernelAvailable()) {
            $this->markTestSkipped('Kernel not available in bundle CI context');
        }

        parent::setUp();

        $kernel = static::createKernel();
        $kernel->boot();

        $this->application = new Application($kernel);
        $container = static::getContainer();

        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->tenantRegistry = $container->get(TenantRegistryInterface::class);
        $this->testData = new TestData($this->entityManager, $this->tenantRegistry);
    }

    protected function tearDown(): void
    {
        $this->testData?->clearAll();
        $this->tenantContext?->clear();

        parent::tearDown();
    }

    /**
     * Check if a Symfony kernel is available for testing.
     */
    private function isKernelAvailable(): bool
    {
        try {
            // Try to get the kernel class
            $kernelClass = static::getKernelClass();

            return class_exists($kernelClass);
        } catch (\LogicException $e) {
            // KERNEL_CLASS not set or kernel class not found
            return false;
        }
    }

    /**
     * Execute a command with tenant context.
     *
     * @param string $commandName The command name
     * @param string $tenantSlug  The tenant slug
     * @param array  $input       Command input (arguments and options)
     * @param array  $options     CommandTester options
     *
     * @return CommandTester The command tester with execution results
     */
    protected function executeCommandWithTenant(
        string $commandName,
        string $tenantSlug,
        array $input = [],
        array $options = [],
    ): CommandTester {
        return $this->withTenant($tenantSlug, function () use ($commandName, $input, $options) {
            return $this->executeCommand($commandName, $input, $options);
        });
    }

    /**
     * Execute a command.
     *
     * @param string $commandName The command name
     * @param array  $input       Command input (arguments and options)
     * @param array  $options     CommandTester options
     *
     * @return CommandTester The command tester with execution results
     */
    protected function executeCommand(string $commandName, array $input = [], array $options = []): CommandTester
    {
        $command = $this->application->find($commandName);
        $commandTester = new CommandTester($command);

        $commandTester->execute($input, $options);

        return $commandTester;
    }

    /**
     * Execute a command with the --tenant option.
     *
     * @param string $commandName The command name
     * @param string $tenantSlug  The tenant slug
     * @param array  $input       Additional command input
     * @param array  $options     CommandTester options
     *
     * @return CommandTester The command tester with execution results
     */
    protected function executeCommandWithTenantOption(
        string $commandName,
        string $tenantSlug,
        array $input = [],
        array $options = [],
    ): CommandTester {
        $input['--tenant'] = $tenantSlug;

        return $this->executeCommand($commandName, $input, $options);
    }

    /**
     * Execute a command with the TENANT_ID environment variable.
     *
     * @param string $commandName The command name
     * @param string $tenantSlug  The tenant slug
     * @param array  $input       Command input
     * @param array  $options     CommandTester options
     *
     * @return CommandTester The command tester with execution results
     */
    protected function executeCommandWithTenantEnv(
        string $commandName,
        string $tenantSlug,
        array $input = [],
        array $options = [],
    ): CommandTester {
        $originalEnv = $_ENV['TENANT_ID'] ?? null;

        try {
            $_ENV['TENANT_ID'] = $tenantSlug;
            putenv("TENANT_ID={$tenantSlug}");

            return $this->executeCommand($commandName, $input, $options);
        } finally {
            if (null !== $originalEnv) {
                $_ENV['TENANT_ID'] = $originalEnv;
                putenv("TENANT_ID={$originalEnv}");
            } else {
                unset($_ENV['TENANT_ID']);
                putenv('TENANT_ID');
            }
        }
    }

    /**
     * Get a command by name.
     *
     * @param string $commandName The command name
     *
     * @return Command The command instance
     */
    protected function getCommand(string $commandName): Command
    {
        return $this->application->find($commandName);
    }

    /**
     * Assert that command output contains tenant-specific information.
     *
     * @param CommandTester $commandTester The command tester
     * @param string        $tenantSlug    The expected tenant slug
     */
    protected function assertCommandOutputContainsTenant(CommandTester $commandTester, string $tenantSlug): void
    {
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString(
            $tenantSlug,
            $output,
            'Command output should contain tenant-specific information'
        );
    }

    /**
     * Assert that command executed successfully.
     *
     * @param CommandTester $commandTester The command tester
     */
    protected function assertCommandIsSuccessful(CommandTester $commandTester): void
    {
        $this->assertSame(
            Command::SUCCESS,
            $commandTester->getStatusCode(),
            sprintf('Command failed with output: %s', $commandTester->getDisplay())
        );
    }

    /**
     * Assert that command failed.
     *
     * @param CommandTester $commandTester The command tester
     */
    protected function assertCommandFailed(CommandTester $commandTester): void
    {
        $this->assertNotSame(
            Command::SUCCESS,
            $commandTester->getStatusCode(),
            'Command should have failed'
        );
    }

    /**
     * Assert that command output contains specific text.
     *
     * @param CommandTester $commandTester The command tester
     * @param string        $expectedText  The expected text
     */
    protected function assertCommandOutputContains(CommandTester $commandTester, string $expectedText): void
    {
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString($expectedText, $output);
    }

    /**
     * Assert that command output does not contain specific text.
     *
     * @param CommandTester $commandTester  The command tester
     * @param string        $unexpectedText The text that should not be present
     */
    protected function assertCommandOutputDoesNotContain(CommandTester $commandTester, string $unexpectedText): void
    {
        $output = $commandTester->getDisplay();
        $this->assertStringNotContainsString($unexpectedText, $output);
    }

    protected function getTenantContext(): TenantContextInterface
    {
        if (!$this->tenantContext) {
            throw new \RuntimeException('TenantContext not initialized. Call setUp() first.');
        }

        return $this->tenantContext;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        if (!$this->entityManager) {
            throw new \RuntimeException('EntityManager not initialized. Call setUp() first.');
        }

        return $this->entityManager;
    }

    protected function getTenantRegistry(): TenantRegistryInterface
    {
        if (!$this->tenantRegistry) {
            throw new \RuntimeException('TenantRegistry not initialized. Call setUp() first.');
        }

        return $this->tenantRegistry;
    }

    protected function getTestData(): TestData
    {
        if (!$this->testData) {
            throw new \RuntimeException('TestData not initialized. Call setUp() first.');
        }

        return $this->testData;
    }

    protected function getApplication(): Application
    {
        if (!$this->application) {
            throw new \RuntimeException('Application not initialized. Call setUp() first.');
        }

        return $this->application;
    }
}
