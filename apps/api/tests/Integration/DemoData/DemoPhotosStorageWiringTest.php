<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\Tests\Support\RecordingLogProcessor;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The filesystem of the photo pool reads from the bucket of the DEMO_PHOTOS_* variables: here they point at the Garage of
 * the stack (an S3 endpoint like the one of R2), so the adapter, the client and the path style are the ones of the real configuration.
 */
final class DemoPhotosStorageWiringTest extends KernelTestCase
{
    use PointsPhotoVariables;

    public function testTheFilesystemOfThePoolReadsAnObjectOfTheBucketTheVariablesPointAt(): void
    {
        $this->pointAtGarage(self::bucket());
        [$writer, $reader] = $this->filesystems();
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

    public function testNothingTheClientsOfThePoolDoIsLoggedWithTheEndpointOrTheBucket(): void
    {
        $bucket = self::bucket();
        $this->pointAtGarage($bucket);
        [$writer, $reader] = $this->filesystems();
        $logs = self::getContainer()->get(RecordingLogProcessor::class);
        self::assertInstanceOf(RecordingLogProcessor::class, $logs);
        $key = 'demo-photos-wiring-test/'.bin2hex(random_bytes(8)).'.tar';
        $writer->write($key, 'the archive of the test');

        try {
            // The writer is the client of the originals (the same Garage and bucket here), so what it logged is not the subject.
            $logs->clear();
            $reader->fileExists($key);
            $reader->readStream($key);
            try {
                $reader->readStream($key.'.missing');
            } catch (\Throwable) {
            }
            $records = $logs->records();
        } finally {
            $writer->delete($key);
        }

        $endpointHost = (string) parse_url((string) getenv('GARAGE_S3_ENDPOINT'), \PHP_URL_HOST);
        self::assertNotSame('', $endpointHost);
        self::assertNotSame('', $bucket);
        $leaks = array_values(array_filter(
            $records,
            static fn (string $record): bool => str_contains($record, $endpointHost) || str_contains($record, $bucket),
        ));
        self::assertSame([], $leaks, 'The clients of the photo pool must log nothing that holds the endpoint or the bucket.');
    }

    /**
     * @return array{FilesystemOperator, FilesystemOperator} the writer of the originals and the reader of the pool
     */
    private function filesystems(): array
    {
        $writer = self::getContainer()->get('test.product_images.storage');
        $reader = self::getContainer()->get('test.demo_photos.storage');
        self::assertInstanceOf(FilesystemOperator::class, $writer);
        self::assertInstanceOf(FilesystemOperator::class, $reader);

        return [$writer, $reader];
    }

    private static function bucket(): string
    {
        $bucket = self::getContainer()->getParameter('app.images_bucket');

        return \is_string($bucket) ? $bucket : '';
    }
}
