<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

/** Test-only non-seekable caller stream; switches context from inside actual stream I/O. */
final class InterruptingStream
{
    public mixed $context;
    public static ?\Closure $onIo = null;
    public static string $written = '';
    public static bool $eof = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        self::$written .= $data;
        (self::$onIo)();

        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        self::$eof = true;
        (self::$onIo)();

        return str_repeat('x', $count);
    }

    public function stream_eof(): bool
    {
        return self::$eof;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
