<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\DependencyInjection\Configuration;
use Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension;
use Zhortein\MultiTenantBundle\ObjectStorage\ConfiguredTenantStorageNamespaceResolver;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\Resolver\TenantResolverInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InstrumentedBackend;
use Zhortein\MultiTenantBundle\ZhorteinMultiTenantBundle;

final class ObjectStorageConfigurationTest extends TestCase
{
    public static function validConfig(): array
    {
        return ['enabled' => true, 'namespace_resolver' => 'test.namespaces',
            'providers' => ['shared' => ['active_location' => 'shared_v1']],
            'locations' => ['shared_v1' => ['backend' => 'test.backend', 'binding' => 'test.backend', 'allowed_tenants' => ['*']]],
        ];
    }

    private function container(array $objectStorage): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir().'/object-storage-config');
        $container->register(TenantResolverInterface::class)->setSynthetic(true)->setPublic(true);
        $container->register('test.backend', InstrumentedBackend::class);
        $container->register('test.namespaces', ConfiguredTenantStorageNamespaceResolver::class)->setArguments([['1' => str_repeat('a', 64)]]);
        (new ZhorteinMultiTenantBundle())->build($container);
        (new ZhorteinMultiTenantExtension())->load([[
            'resolver' => 'custom', 'database' => ['rls' => ['enabled' => false]],
            'decorators' => ['cache' => ['enabled' => false], 'logger' => ['enabled' => false]],
            'fixtures' => ['enabled' => false], 'mailer' => ['enabled' => false],
            'messenger' => ['enabled' => false], 'storage' => ['enabled' => false],
            'object_storage' => $objectStorage,
        ]], $container);

        return $container;
    }

    public function testDisabledRegistersNoOperationalServicesAndRequiresNoBackend(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertFalse($config['object_storage']['enabled']);
        self::assertFalse($config['object_storage']['temporary_urls']['enabled']);
        $container = $this->container([]);
        foreach (array_keys($container->getDefinitions()) as $id) {
            self::assertStringNotContainsString('zhortein_multi_tenant.object_storage', $id);
        }
        self::assertFalse($container->has(TenantObjectStorageInterface::class));
        $container->compile();
        self::assertFalse($container->has(TenantObjectStorageInterface::class));
    }

    public function testEnabledRegistersExplicitServicesAndBothResetHooksWithoutFlysystem(): void
    {
        self::assertFalse(interface_exists('League\\Flysystem\\FilesystemOperator'));
        $container = $this->container(self::validConfig());
        $definition = $container->getDefinition('zhortein_multi_tenant.object_storage');
        self::assertTrue($definition->hasTag('kernel.reset'));
        self::assertTrue($definition->hasTag('zhortein_multi_tenant.lifecycle_resetter'));
        self::assertTrue($definition->hasTag('kernel.event_subscriber'));
        // ContainerBuilder removes unused services in this minimal compilation fixture.
        $container->compile();
        self::assertTrue($container->isCompiled());
    }

    public static function invalidConfigs(): iterable
    {
        yield 'missing required active config' => [['enabled' => true]];
        yield 'blank default' => [['default_provider' => '']];
        yield 'numeric default' => [['default_provider' => 1]];
        yield 'unknown default' => [['default_provider' => 'missing']];
        yield 'blank namespaces' => [['namespace_resolver' => ' ']];
        yield 'numeric namespaces' => [['namespace_resolver' => 1]];
        yield 'blank selector' => [['provider_selector' => '']];
        yield 'unknown override' => [['tenant_overrides' => ['1' => 'missing']]];
        yield 'empty tenant' => [['tenant_overrides' => ['' => 'shared']]];
        yield 'wildcard tenant override' => [['tenant_overrides' => ['*' => 'shared']]];
        yield 'zero TTL' => [['temporary_urls' => ['default_ttl' => 0]]];
        yield 'negative TTL' => [['temporary_urls' => ['max_ttl' => -1]]];
        yield 'inconsistent TTL' => [['temporary_urls' => ['default_ttl' => 901]]];
        yield 'excessive maximum TTL' => [['temporary_urls' => ['max_ttl' => 86401]]];
        yield 'unknown active location' => [['providers' => ['shared' => ['active_location' => 'missing']]]];
        yield 'invalid provider id' => [['providers' => ['bad/id' => ['active_location' => 'shared_v1']]]];
        yield 'empty provider' => [['providers' => ['empty' => []]]];
        yield 'empty location' => [['locations' => ['empty_v1' => []]]];
        yield 'empty backend' => [['locations' => ['shared_v1' => ['backend' => '']]]];
        yield 'blank binding' => [['locations' => ['shared_v1' => ['binding' => ' ']]]];
        yield 'empty allow list' => [['locations' => ['shared_v1' => ['allowed_tenants' => []]]]];
        yield 'duplicate allowed tenant' => [['locations' => ['shared_v1' => ['allowed_tenants' => ['1', '1']]]]];
        yield 'empty allowed tenant' => [['locations' => ['shared_v1' => ['allowed_tenants' => ['']]]]];
        yield 'mixed wildcard allow list' => [['locations' => ['shared_v1' => ['allowed_tenants' => ['*', '1']]]]];
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationIsReadableAndFailClosed(array $changes): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $config = array_replace_recursive(self::validConfig(), $changes);
        // Explicit empty arrays must replace required maps/lists, not merge away.
        if (isset($changes['locations']['shared_v1']['allowed_tenants'])) {
            $config['locations']['shared_v1']['allowed_tenants'] = $changes['locations']['shared_v1']['allowed_tenants'];
        }
        if (['enabled' => true] === $changes) {
            $config = $changes;
        }
        (new Processor())->processConfiguration(new Configuration(), [['object_storage' => $config]]);
    }

    public function testDuplicateGenerationConfigurationCannotBeOverwritten(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [
            ['object_storage' => self::validConfig()], ['object_storage' => self::validConfig()],
        ]);
    }

    public static function badServices(): iterable
    {
        yield 'missing namespace service' => ['test.namespaces', null];
        yield 'wrong namespace type' => ['test.namespaces', \stdClass::class];
        yield 'missing backend service' => ['test.backend', null];
        yield 'wrong backend type' => ['test.backend', \stdClass::class];
    }

    #[DataProvider('badServices')]
    public function testMissingOrWrongServiceTypesFailAtCompilation(string $id, ?string $class): void
    {
        $container = $this->container(self::validConfig());
        if (null === $class) {
            $container->removeDefinition($id);
        } else {
            $container->getDefinition($id)->setClass($class);
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('object_storage:');
        $container->compile();
    }

    public function testConfiguredSigningCapabilityMustMatchBackendType(): void
    {
        $config = self::validConfig();
        $config['locations']['shared_v1']['temporary_urls'] = true;
        $container = $this->container($config);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('TemporaryObjectUrlBackendInterface');
        $container->compile();
    }
}
