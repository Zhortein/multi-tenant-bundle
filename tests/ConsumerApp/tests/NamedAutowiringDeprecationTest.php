<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class NamedAutowiringDeprecationTest extends TestCase
{
    public function testProductionContainerDoesNotRelyOnParameterNamesForNamedAutowiringAliases(): void
    {
        $deprecations = [];
        set_error_handler(
            static function (int $severity, string $message) use (&$deprecations): bool {
                if (E_USER_DEPRECATED !== $severity || !str_contains($message, 'named autowiring alias')) {
                    return false;
                }

                $deprecations[] = $message;

                return true;
            },
        );
        $kernel = new Kernel('named_autowiring_deprecations_'.bin2hex(random_bytes(8)), false);

        try {
            $kernel->boot();
        } finally {
            $kernel->shutdown();
            restore_error_handler();
        }

        self::assertSame([], $deprecations, implode(PHP_EOL, $deprecations));
    }
}
