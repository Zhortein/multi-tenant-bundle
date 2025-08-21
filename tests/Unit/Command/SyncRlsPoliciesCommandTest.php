<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zhortein\MultiTenantBundle\Attribute\AsTenantAware;
use Zhortein\MultiTenantBundle\Command\SyncRlsPoliciesCommand;

/**
 * Unit tests for the SyncRlsPoliciesCommand.
 *
 * @covers \Zhortein\MultiTenantBundle\Command\SyncRlsPoliciesCommand
 */
final class SyncRlsPoliciesCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private ClassMetadataFactory&MockObject $metadataFactory;
    private SyncRlsPoliciesCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->metadataFactory = $this->createMock(ClassMetadataFactory::class);

        $this->entityManager
            ->method('getMetadataFactory')
            ->willReturn($this->metadataFactory);

        $this->command = new SyncRlsPoliciesCommand(
            $this->entityManager,
            $this->connection,
            'app.tenant_id',
            'tenant_isolation'
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteWithNonPostgreSQLDatabase(): void
    {
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class);
        $platform->method('getName')->willReturn('mysql');

        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('RLS policies are only supported with PostgreSQL', $this->commandTester->getDisplay());
    }

    public function testExecuteWithNoTenantAwareEntities(): void
    {
        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->metadataFactory
            ->method('getAllMetadata')
            ->willReturn([]);

        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No tenant-aware entities found', $this->commandTester->getDisplay());
    }

    public function testExecuteGeneratesSqlForTenantAwareEntities(): void
    {
        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        // Mock entity metadata
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Product');
        $metadata->method('getTableName')->willReturn('products');

        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $metadata->method('getReflectionClass')->willReturn($reflectionClass);

        // Mock AsTenantAware attribute
        $attribute = $this->createMock(\ReflectionAttribute::class);
        $attribute->method('newInstance')->willReturn(new AsTenantAware(requireTenantId: true));

        $reflectionClass->method('getAttributes')
            ->with(AsTenantAware::class)
            ->willReturn([$attribute]);

        $this->metadataFactory
            ->method('getAllMetadata')
            ->willReturn([$metadata]);

        // Mock connection methods for checking existing state
        $this->connection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, false); // RLS not enabled, policy doesn't exist

        $this->connection
            ->method('quoteIdentifier')
            ->willReturnCallback(fn ($identifier) => '"'.$identifier.'"');

        $this->connection
            ->method('quote')
            ->willReturnCallback(fn ($value) => "'".$value."'");

        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('Found 1 tenant-aware entities', $output);
        $this->assertStringContainsString('ALTER TABLE "products" ENABLE ROW LEVEL SECURITY', $output);
        $this->assertStringContainsString('CREATE POLICY "tenant_isolation_products" ON "products"', $output);
        $this->assertStringContainsString('tenant_id::text = current_setting(\'app.tenant_id\', true)', $output);
        $this->assertStringContainsString('Use --apply option to execute', $output);
    }

    public function testExecuteWithApplyOption(): void
    {
        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        // Mock entity metadata
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Product');
        $metadata->method('getTableName')->willReturn('products');

        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $metadata->method('getReflectionClass')->willReturn($reflectionClass);

        // Mock AsTenantAware attribute
        $attribute = $this->createMock(\ReflectionAttribute::class);
        $attribute->method('newInstance')->willReturn(new AsTenantAware(requireTenantId: true));

        $reflectionClass->method('getAttributes')
            ->with(AsTenantAware::class)
            ->willReturn([$attribute]);

        $this->metadataFactory
            ->method('getAllMetadata')
            ->willReturn([$metadata]);

        // Mock connection methods
        $this->connection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, false); // RLS not enabled, policy doesn't exist

        $this->connection
            ->method('quoteIdentifier')
            ->willReturnCallback(fn ($identifier) => '"'.$identifier.'"');

        $this->connection
            ->method('quote')
            ->willReturnCallback(fn ($value) => "'".$value."'");

        // Expect SQL statements to be executed
        $expectedCalls = [
            'ALTER TABLE "products" ENABLE ROW LEVEL SECURITY;',
            'CREATE POLICY "tenant_isolation_products" ON "products" USING (tenant_id::text = current_setting(\'app.tenant_id\', true));',
        ];

        $this->connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) use (&$expectedCalls) {
                $this->assertContains($sql, $expectedCalls);

                return null;
            });

        $exitCode = $this->commandTester->execute(['--apply' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Successfully applied 2 SQL statements', $this->commandTester->getDisplay());
    }

    public function testExecuteWithForceOption(): void
    {
        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        // Mock entity metadata
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Product');
        $metadata->method('getTableName')->willReturn('products');

        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $metadata->method('getReflectionClass')->willReturn($reflectionClass);

        // Mock AsTenantAware attribute
        $attribute = $this->createMock(\ReflectionAttribute::class);
        $attribute->method('newInstance')->willReturn(new AsTenantAware(requireTenantId: true));

        $reflectionClass->method('getAttributes')
            ->with(AsTenantAware::class)
            ->willReturn([$attribute]);

        $this->metadataFactory
            ->method('getAllMetadata')
            ->willReturn([$metadata]);

        // Mock connection methods - RLS enabled, policy exists
        $this->connection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(true, true); // RLS enabled, policy exists

        $this->connection
            ->method('quoteIdentifier')
            ->willReturnCallback(fn ($identifier) => '"'.$identifier.'"');

        $this->connection
            ->method('quote')
            ->willReturnCallback(fn ($value) => "'".$value."'");

        $exitCode = $this->commandTester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();

        // Should include DROP POLICY statement when forcing
        $this->assertStringContainsString('DROP POLICY IF EXISTS "tenant_isolation_products"', $output);
        $this->assertStringContainsString('CREATE POLICY "tenant_isolation_products"', $output);
    }

    public function testExecuteHandlesDatabaseException(): void
    {
        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        // Mock entity metadata
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Product');
        $metadata->method('getTableName')->willReturn('products');

        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $metadata->method('getReflectionClass')->willReturn($reflectionClass);

        // Mock AsTenantAware attribute
        $attribute = $this->createMock(\ReflectionAttribute::class);
        $attribute->method('newInstance')->willReturn(new AsTenantAware(requireTenantId: true));

        $reflectionClass->method('getAttributes')
            ->with(AsTenantAware::class)
            ->willReturn([$attribute]);

        $this->metadataFactory
            ->method('getAllMetadata')
            ->willReturn([$metadata]);

        // Mock connection methods to throw exception
        $this->connection
            ->method('fetchOne')
            ->willThrowException(new Exception('Database error'));

        $this->connection
            ->method('quoteIdentifier')
            ->willReturnCallback(fn ($identifier) => '"'.$identifier.'"');

        $this->connection
            ->method('quote')
            ->willReturnCallback(fn ($value) => "'".$value."'");

        // Should still generate SQL even when checking existing state fails
        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('ALTER TABLE "products" ENABLE ROW LEVEL SECURITY', $output);
        $this->assertStringContainsString('CREATE POLICY "tenant_isolation_products"', $output);
    }

    public function testExecuteSkipsEntitiesWithoutTenantId(): void
    {
        $platform = new PostgreSQLPlatform();
        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        // Mock entity metadata
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Product');
        $metadata->method('getTableName')->willReturn('products');

        $reflectionClass = $this->createMock(\ReflectionClass::class);
        $metadata->method('getReflectionClass')->willReturn($reflectionClass);

        // Mock AsTenantAware attribute with requireTenantId = false (multi-db mode)
        $attribute = $this->createMock(\ReflectionAttribute::class);
        $attribute->method('newInstance')->willReturn(new AsTenantAware(requireTenantId: false));

        $reflectionClass->method('getAttributes')
            ->with(AsTenantAware::class)
            ->willReturn([$attribute]);

        $this->metadataFactory
            ->method('getAllMetadata')
            ->willReturn([$metadata]);

        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No tenant-aware entities found', $this->commandTester->getDisplay());
    }
}
