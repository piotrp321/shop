<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Image;

use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;

/**
 * A storage that cannot be reached is an outage (a 503 of the API), not a missing or a rejected file.
 */
final class FlysystemProductImageStorageOutageTest extends TestCase
{
    private const string KEY = 'originals/0000000000000000000000000000000000000000000000000000000000000000.png';

    public function testExistsReportsAnOutageWhenTheStorageCannotAnswer(): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileExists')->willThrowException(UnableToCheckFileExistence::forLocation(self::KEY));

        $this->expectException(ProductImageStorageUnavailable::class);

        new FlysystemProductImageStorage($filesystem, 1000, 1000)->exists(self::KEY);
    }

    public function testStoreReportsAnOutageWhenTheStorageCannotWrite(): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('write')->willThrowException(UnableToWriteFile::atLocation(self::KEY));
        $png = (string) file_get_contents(__DIR__.'/../../../Fixtures/images/sample.png');

        $this->expectException(ProductImageStorageUnavailable::class);

        new FlysystemProductImageStorage($filesystem, 10_000_000, 10_000_000)->store($png);
    }
}
