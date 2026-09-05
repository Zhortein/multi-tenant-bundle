<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Test;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamDestinationInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStreamSourceInterface;

/**
 * Optional reusable adapter contract. Requires PHPUnit only in the consuming test environment.
 * Supply a fresh, isolated disposable target; never use production objects or credentials.
 */
abstract class ObjectStorageBackendTestCase extends TestCase
{
    abstract protected function createBackend(): ObjectStorageBackendInterface;

    public function testObjectStorageBackendContract(): void
    {
        $backend = $this->createBackend();
        $prefix = 'objects/v1/'.bin2hex(random_bytes(32)).'/';
        $source = $prefix.str_repeat('1', 64);
        $copy = $prefix.str_repeat('2', 64);
        $moved = $prefix.str_repeat('3', 64);
        $foreign = 'objects/v1/'.bin2hex(random_bytes(32)).'/'.str_repeat('1', 64);
        try {
            self::assertFalse($backend->exists($source));
            $backend->write($source, 'contract');
            $backend->write($foreign, 'foreign');
            self::assertTrue($backend->exists($source));
            self::assertSame('contract', $backend->read($source));
            self::assertSame(8, $backend->metadata($source)->size);
            $backend->copy($source, $copy);
            self::assertSame('contract', $backend->read($copy));
            self::assertTrue($backend->exists($source));
            $page = $backend->list($prefix, 1);
            self::assertSame([$source], $page->keys);
            self::assertTrue($page->hasMore);
            $page = $backend->list($prefix, 1, $source);
            self::assertSame([$copy], $page->keys);
            self::assertFalse($page->hasMore);
            $backend->move($copy, $moved);
            self::assertFalse($backend->exists($copy));
            self::assertSame('contract', $backend->read($moved));
            $input = new class implements ObjectStreamSourceInterface {
                private bool $consumed = false;

                public function readChunk(): string
                {
                    if ($this->consumed) {
                        return '';
                    }
                    $this->consumed = true;

                    return "stream\0bytes";
                }

                public function eof(): bool
                {
                    return $this->consumed;
                }
            };
            $output = new class implements ObjectStreamDestinationInterface {
                public string $content = '';

                public function writeChunk(string $chunk): void
                {
                    if (strlen($chunk) > 65536) {
                        throw new \LogicException('Adapter must bound chunks to 64 KiB.');
                    }
                    $this->content .= $chunk;
                }
            };
            $backend->writeFromStream($source, $input);
            $backend->readToStream($source, $output);
            self::assertSame("stream\0bytes", $output->content);
            $backend->delete($source);
            $backend->delete($source);
            self::assertFalse($backend->exists($source));
            try {
                $backend->read($source);
                self::fail('Reading an absent object must throw.');
            } catch (ObjectStorageException $exception) {
                self::assertSame(ObjectStorageError::OBJECT_NOT_FOUND, $exception->reason);
            }
        } finally {
            foreach ([$source, $copy, $moved, $foreign] as $key) {
                $backend->delete($key);
            }
        }
    }
}
