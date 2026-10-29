<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Image;

use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use App\Catalog\Infrastructure\Image\InvalidImage;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FlysystemProductImageStorageTest extends KernelTestCase
{
    private FilesystemOperator $filesystem;

    protected function setUp(): void
    {
        $filesystem = self::getContainer()->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $filesystem);
        $this->filesystem = $filesystem;
        $this->storage()->purge();
    }

    protected function tearDown(): void
    {
        $this->storage()->purge();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function supportedFixtures(): iterable
    {
        foreach (['jpg', 'png', 'webp', 'avif', 'heic'] as $extension) {
            yield $extension => [$extension];
        }
    }

    #[DataProvider('supportedFixtures')]
    public function testStoresEachSupportedTypeUnderItsContentHash(string $extension): void
    {
        $contents = self::fixture('sample.'.$extension);

        $key = $this->storage()->store($contents);

        self::assertSame('originals/'.hash('sha256', $contents).'.'.$extension, $key);
        self::assertSame($key, ImageKey::fromString($key)->toString());
        self::assertTrue($this->filesystem->fileExists($key));
        self::assertSame($contents, $this->filesystem->read($key));
    }

    public function testSameContentGivesSameKey(): void
    {
        $contents = self::fixture('sample.png');

        self::assertSame($this->storage()->store($contents), $this->storage()->store($contents));
        self::assertCount(1, $this->objects());
    }

    public function testTypeComesFromTheContentNotTheFileName(): void
    {
        $pngNamedJpg = (string) file_get_contents(self::fixturePath('sample.png'));

        self::assertStringEndsWith('.png', $this->storage()->store($pngNamedJpg));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedContents(): iterable
    {
        $svg = self::fixture('sample.svg');

        yield 'svg with xml prolog' => [$svg];
        yield 'svg without prolog' => [substr($svg, (int) strpos($svg, '<svg'))];
        yield 'html' => ['<!doctype html><html><body><script>alert(1)</script></body></html>'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsupportedContents')]
    public function testRejectsUnsupportedContentWithoutWriting(string $contents): void
    {
        try {
            $this->storage()->store($contents);
            self::fail('Expected InvalidImage.');
        } catch (InvalidImage) {
            // Expected.
        }

        self::assertSame([], $this->objects());
    }

    public function testFileSizeLimitIsInclusive(): void
    {
        $contents = self::fixture('sample.png');
        $storage = $this->storageWithLimits(\strlen($contents), 50_000_000);

        self::assertStringEndsWith('.png', $storage->store($contents));

        $this->storage()->purge();
        $tooSmallLimit = $this->storageWithLimits(\strlen($contents) - 1, 50_000_000);

        $this->expectException(InvalidImage::class);
        try {
            $tooSmallLimit->store($contents);
        } finally {
            self::assertSame([], $this->objects());
        }
    }

    public function testSizeIsCheckedBeforeTheType(): void
    {
        $storage = $this->storageWithLimits(3, 50_000_000);

        try {
            $storage->store('not an image at all');
            self::fail('Expected InvalidImage.');
        } catch (InvalidImage $e) {
            self::assertSame('The image file is too large.', $e->getMessage());
        }
    }

    public function testRejectsResolutionAboveTheLimit(): void
    {
        $storage = $this->storageWithLimits(67_108_864, 64 * 48 - 1);

        try {
            $storage->store(self::fixture('sample.png'));
            self::fail('Expected InvalidImage.');
        } catch (InvalidImage) {
            self::assertSame([], $this->objects());
        }
    }

    public function testAcceptsResolutionEqualToTheLimit(): void
    {
        $storage = $this->storageWithLimits(67_108_864, 64 * 48);

        self::assertStringEndsWith('.png', $storage->store(self::fixture('sample.png')));
    }

    public function testRejectsOversizedImageWithContainerLimits(): void
    {
        try {
            $this->storage()->store(self::fixture('oversized.webp'));
            self::fail('Expected InvalidImage.');
        } catch (InvalidImage) {
            self::assertSame([], $this->objects());
        }
    }

    public function testRejectsImageWithoutReadableDimensions(): void
    {
        // Valid PNG signature, truncated before the header chunk.
        $this->expectException(InvalidImage::class);

        $this->storage()->store("\x89PNG\r\n\x1a\n");
    }

    public function testPurgeRemovesStoredOriginals(): void
    {
        $this->storage()->store(self::fixture('sample.jpg'));
        $this->storage()->store(self::fixture('sample.webp'));
        self::assertCount(2, $this->objects());

        $this->storage()->purge();

        self::assertSame([], $this->objects());
    }

    public function testContainerServiceUsesTheDocumentedLimits(): void
    {
        $storage = $this->storage();
        self::assertInstanceOf(FlysystemProductImageStorage::class, $storage);

        $reflection = new \ReflectionObject($storage);
        self::assertSame(67_108_864, $reflection->getProperty('maxBytes')->getValue($storage));
        self::assertSame(50_000_000, $reflection->getProperty('maxPixels')->getValue($storage));
    }

    private function storage(): ProductImageStorage
    {
        $storage = self::getContainer()->get(ProductImageStorage::class);
        self::assertInstanceOf(ProductImageStorage::class, $storage);

        return $storage;
    }

    private function storageWithLimits(int $maxBytes, int $maxPixels): ProductImageStorage
    {
        return new FlysystemProductImageStorage($this->filesystem, $maxBytes, $maxPixels);
    }

    /** @return list<string> */
    private function objects(): array
    {
        return array_values($this->filesystem->listContents('', true)
            ->filter(static fn ($item): bool => $item->isFile())
            ->map(static fn ($item): string => $item->path())
            ->toArray());
    }

    private static function fixturePath(string $name): string
    {
        return __DIR__.'/../../../Fixtures/images/'.$name;
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::fixturePath($name));
    }
}
