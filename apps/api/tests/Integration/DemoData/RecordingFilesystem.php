<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;

/**
 * A filesystem that does what the one it wraps does and remembers the writes and the reads of the test; it can be made to
 * fail every read with an exception of the test.
 */
final class RecordingFilesystem implements FilesystemOperator
{
    /** @var list<string> */
    public array $writes = [];
    public int $reads = 0;
    public ?\Throwable $failReads = null;

    public function __construct(private readonly FilesystemOperator $inner)
    {
    }

    public function fileExists(string $location): bool
    {
        $this->countRead();

        return $this->inner->fileExists($location);
    }

    public function directoryExists(string $location): bool
    {
        return $this->inner->directoryExists($location);
    }

    public function has(string $location): bool
    {
        return $this->inner->has($location);
    }

    public function read(string $location): string
    {
        $this->countRead();

        return $this->inner->read($location);
    }

    public function readStream(string $location)
    {
        $this->countRead();

        return $this->inner->readStream($location);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return $this->inner->listContents($location, $deep);
    }

    public function lastModified(string $path): int
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): int
    {
        return $this->inner->fileSize($path);
    }

    public function mimeType(string $path): string
    {
        return $this->inner->mimeType($path);
    }

    public function visibility(string $path): string
    {
        return $this->inner->visibility($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->writes[] = $location;
        $this->inner->write($location, $contents, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->writes[] = $location;
        $this->inner->writeStream($location, $contents, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function delete(string $location): void
    {
        $this->inner->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->inner->deleteDirectory($location);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->inner->createDirectory($location, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->inner->move($source, $destination, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->inner->copy($source, $destination, $config);
    }

    private function countRead(): void
    {
        ++$this->reads;
        if (null !== $this->failReads) {
            throw $this->failReads;
        }
    }
}
