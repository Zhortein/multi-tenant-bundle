<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Decorator\TenantCacheException;
use Zhortein\MultiTenantBundle\Exception\DirtyEntityManagerException;
use Zhortein\MultiTenantBundle\Exception\DoctrineProtectionException;
use Zhortein\MultiTenantBundle\Exception\MissingTenantContextException;
use Zhortein\MultiTenantBundle\Exception\MultiTenantException;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Exception\TenantNotFoundException;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;
use Zhortein\MultiTenantBundle\Storage\TenantStorageException;

final class PublicExceptionHierarchyTest extends TestCase
{
    /** @return iterable<string, array{class-string<\Throwable>}> */
    public static function bundleExceptions(): iterable
    {
        foreach ([
            TenantCacheException::class,
            DirtyEntityManagerException::class,
            DoctrineProtectionException::class,
            MissingTenantContextException::class,
            TenantContextTransitionException::class,
            TenantNotFoundException::class,
            TenantResolutionException::class,
            TenantStorageException::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('bundleExceptions')]
    public function testPublicExceptionImplementsCommonContract(string $class): void
    {
        self::assertTrue(is_a($class, MultiTenantException::class, true));
    }
}
