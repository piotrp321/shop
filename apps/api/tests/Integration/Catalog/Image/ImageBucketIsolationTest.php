<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Image;

use App\Catalog\Application\Port\ProductImageStorage;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImageBucketIsolationTest extends KernelTestCase
{
    public function testTestKernelUsesTheTestBucket(): void
    {
        self::assertSame('product-images-test', self::getContainer()->getParameter('app.images_bucket'));
    }

    public function testStoredOriginalLandsInTheTestBucketAndNotInTheDevBucket(): void
    {
        $storage = self::getContainer()->get(ProductImageStorage::class);
        self::assertInstanceOf(ProductImageStorage::class, $storage);
        $storage->purge();

        try {
            // The key is the hash of the content, so the fixture as it is may already be in the dev bucket (an
            // upload by hand). Bytes after the end of the PNG are ignored by readers and make this image unique.
            $key = $storage->store((string) file_get_contents(__DIR__.'/../../../Fixtures/images/sample.png').bin2hex(random_bytes(16)));

            self::assertTrue($this->bucket('product-images-test')->fileExists($key));
            self::assertFalse($this->bucket('product-images')->fileExists($key));
        } finally {
            $storage->purge();
        }
    }

    private function bucket(string $name): Filesystem
    {
        $client = new S3Client([
            'endpoint' => self::env('GARAGE_S3_ENDPOINT'),
            'region' => 'garage',
            'pathStyleEndpoint' => 'true',
            'accessKeyId' => self::env('GARAGE_ACCESS_KEY_ID'),
            'accessKeySecret' => self::env('GARAGE_SECRET_ACCESS_KEY'),
        ]);

        return new Filesystem(new AsyncAwsS3Adapter($client, $name));
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        self::assertIsString($value);

        return $value;
    }
}
