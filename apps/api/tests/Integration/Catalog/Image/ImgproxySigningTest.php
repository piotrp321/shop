<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Image;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Domain\Product\ImageKey;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImgproxySigningTest extends KernelTestCase
{
    use GuardsTestBucket;

    private const string IMGPROXY_URL = 'http://imgproxy:8080';

    private FilesystemOperator $filesystem;
    private ProductImageStorage $storage;

    protected function setUp(): void
    {
        self::assertUsesTestBucket();
        $filesystem = self::getContainer()->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $filesystem);
        $storage = self::getContainer()->get(ProductImageStorage::class);
        self::assertInstanceOf(ProductImageStorage::class, $storage);

        $this->filesystem = $filesystem;
        $this->storage = $storage;
        $this->storage->purge();
    }

    protected function tearDown(): void
    {
        self::assertUsesTestBucket();
        $this->storage->purge();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function sourceTypes(): iterable
    {
        foreach (['jpg', 'png', 'webp', 'avif', 'heic'] as $extension) {
            yield $extension => [$extension];
        }
    }

    #[DataProvider('sourceTypes')]
    public function testSignedUrlServesWebpForEverySourceType(string $extension): void
    {
        $key = $this->storage->store((string) file_get_contents(__DIR__.'/../../../Fixtures/images/sample.'.$extension));

        [$status, $contentType] = $this->fetch($this->signedUrl($key));

        self::assertSame(200, $status);
        self::assertSame('image/webp', $contentType);
    }

    public function testTamperedSignatureIsForbidden(): void
    {
        $key = $this->storage->store((string) file_get_contents(__DIR__.'/../../../Fixtures/images/sample.png'));
        $url = $this->signedUrl($key);

        $prefix = self::IMGPROXY_URL.'/';
        $signature = substr($url, \strlen($prefix), 43);
        $flipped = ('A' === $signature[0] ? 'B' : 'A').substr($signature, 1);

        [$status] = $this->fetch($prefix.$flipped.substr($url, \strlen($prefix) + 43));

        self::assertSame(403, $status);
    }

    public function testOversizedSourceIsRejectedByImgproxy(): void
    {
        $contents = (string) file_get_contents(__DIR__.'/../../../Fixtures/images/oversized.webp');
        $key = 'originals/'.hash('sha256', $contents).'.webp';
        ImageKey::fromString($key);
        $this->filesystem->write($key, $contents);

        [$status] = $this->fetch($this->signedUrl($key));

        self::assertSame(422, $status);
    }

    /** Signed URL for the container network: the browser-facing base is swapped for the internal imgproxy address. */
    private function signedUrl(string $key): string
    {
        $generator = self::getContainer()->get(ImageUrlGenerator::class);
        self::assertInstanceOf(ImageUrlGenerator::class, $generator);
        $publicUrl = self::getContainer()->getParameter('app.imgproxy_public_url');
        self::assertIsString($publicUrl);

        $url = $generator->url($key, ImageSize::Listing, 256, ImageFormat::Webp);
        self::assertStringStartsWith($publicUrl.'/', $url);

        return self::IMGPROXY_URL.substr($url, \strlen($publicUrl));
    }

    /** @return array{int, string} */
    private function fetch(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]);
        $body = file_get_contents($url, false, $context);
        self::assertNotFalse($body, 'imgproxy is unreachable.');

        $status = 0;
        $contentType = '';
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (1 === preg_match('#^HTTP/\S+ (\d{3})#', $line, $matches)) {
                $status = (int) $matches[1];
            } elseif (0 === stripos($line, 'Content-Type:')) {
                $contentType = trim(substr($line, \strlen('Content-Type:')));
            }
        }

        return [$status, $contentType];
    }
}
