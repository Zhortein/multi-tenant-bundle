<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Command\SyncRlsPoliciesCommand;

/**
 * Integration tests for the SyncRlsPoliciesCommand.
 *
 * @group integration
 * @group command
 */
final class SyncRlsPoliciesCommandIntegrationTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private ClassMetadataFactory&MockObject $metadataFactory;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->metadataFactory = $this->createMock(ClassMetadataFactory::class);

        $this->entityManager
            ->method('getMetadataFactory')
            ->willReturn($this->metadataFactory);
    }

    public function testCommandCanBeInstantiated(): void
    {
        $command = new SyncRlsPoliciesCommand(
            $this->entityManager,
            $this->connection,
            'app.tenant_id',
            'tenant_isolation'
        );

        $this->assertInstanceOf(SyncRlsPoliciesCommand::class, $command);
        $this->assertSame('tenant:rls:sync', $command->getName());
    }

    public function testCommandExecutesWithNonPostgreSQLDatabase(): void
    {
        $platform = new MySQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $command = new SyncRlsPoliciesCommand(
            $this->entityManager,
            $this->connection,
            'app.tenant_id',
            'tenant_isolation'
        );

        $application = new Application();
        $application->add($command);
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([]);

        $this->assertSame(1, $exitCode); // FAILURE for non-PostgreSQL
        $this->assertStringContainsString('RLS policies are only supported with PostgreSQL', $commandTester->getDisplay());
    }

    public function testCommandHasCorrectNameAndDescription(): void
    {
        $command = new SyncRlsPoliciesCommand(
            $this->entityManager,
            $this->connection,
            'app.tenant_id',
            'tenant_isolation'
        );

        $this->assertSame('tenant:rls:sync', $command->getName());
        $this->assertStringContainsString('PostgreSQL Row-Level Security', $command->getDescription());

        // Check that the command has the expected options
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('apply'));
        $this->assertTrue($definition->hasOption('force'));
    }
}
