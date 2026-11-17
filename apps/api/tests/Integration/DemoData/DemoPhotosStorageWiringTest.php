<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The filesystem of the photo pool reads from the bucket of the DEMO_PHOTOS_* variables: here they point at the Garage of
 * the stack (an S3 endpoint like the one of R2), so the adapter, the client and the path style are the ones of the real configuration.
 */
final class DemoPhotosStorageWiringTest extends KernelTestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            false === $value ? putenv($name) : putenv($name.'='.$value);
        }
    }

    public function testTheFilesystemOfThePoolReadsAnObjectOfTheBucketTheVariablesPointAt(): void
    {
        $this->point('DEMO_PHOTOS_REGION', 'garage');
        $this->point('DEMO_PHOTOS_ENDPOINT', (string) getenv('GARAGE_S3_ENDPOINT'));
        $this->point('DEMO_PHOTOS_BUCKET', self::getContainer()->getParameter('app.images_bucket') ?: '');
        $this->point('DEMO_PHOTOS_ACCESS_KEY_ID', (string) getenv('GARAGE_ACCESS_KEY_ID'));
        $this->point('DEMO_PHOTOS_SECRET_ACCESS_KEY', (string) getenv('GARAGE_SECRET_ACCESS_KEY'));
        $writer = self::getContainer()->get('test.product_images.storage');
        $reader = self::getContainer()->get('test.demo_photos.storage');
        self::assertInstanceOf(FilesystemOperator::class, $writer);
        self::assertInstanceOf(FilesystemOperator::class, $reader);
        $key = 'demo-photos-wiring-test/'.bin2hex(random_bytes(8)).'.tar';
        $writer->write($key, 'the archive of the test');

        try {
            self::assertTrue($reader->fileExists($key));
            self::assertSame('the archive of the test', stream_get_contents($reader->readStream($key)));
            self::assertFalse($reader->fileExists($key.'.missing'));
        } finally {
            $writer->delete($key);
        }
    }

    private function point(string $name, mixed $value): void
    {
        self::assertIsString($value);
        self::assertNotSame('', $value, $name.' of the stack is needed by the test.');
        $this->saved[$name] = getenv($name);
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
