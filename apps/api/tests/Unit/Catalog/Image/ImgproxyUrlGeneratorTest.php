<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Image;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Infrastructure\Image\ImgproxyUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImgproxyUrlGeneratorTest extends TestCase
{
    private const string KEY = 'originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';

    public function testSignsThePathAsDocumentedByImgproxy(): void
    {
        $generator = new ImgproxyUrlGenerator('http://localhost:8081', '736563726574', '68656C6C6F', 'product-images');

        self::assertSame(
            'oKfUtW34Dvo2BGQehJFR4Nr0_rIjOtdtzJ3QFsUcXH8',
            $generator->sign('/rs:fill:300:400:0/g:sm/aHR0cDovL2V4YW1w/bGUuY29tL2ltYWdl/cy9jdXJpb3NpdHku/anBn.png'),
        );
    }

    public function testUrlCarriesResizeGravityAndFormat(): void
    {
        $url = $this->generator()->url(self::KEY, ImageSize::Listing, 384, ImageFormat::Webp);

        self::assertStringContainsString('/rs:fill:384:384/g:sm/', $url);
        self::assertStringEndsWith('.webp', $url);
    }

    public function testUrlEncodesTheBucketSourceAndStartsWithThePublicUrl(): void
    {
        $url = $this->generator()->url(self::KEY, ImageSize::Listing, 384, ImageFormat::Webp);

        self::assertStringStartsWith('http://localhost:8081/', $url);
        $encoded = substr($url, strrpos($url, '/') + 1, -\strlen('.webp'));
        self::assertSame('s3://product-images/'.self::KEY, base64_decode(strtr($encoded, '-_', '+/'), true));
    }

    public function testSignatureMatchesThePathInTheUrl(): void
    {
        $generator = $this->generator();
        $url = $generator->url(self::KEY, ImageSize::Listing, 384, ImageFormat::Webp);

        $path = substr($url, \strlen('http://localhost:8081'));
        $signature = explode('/', $path)[1];

        self::assertSame($generator->sign(substr($path, \strlen($signature) + 1)), $signature);
    }

    public function testDifferentWidthGivesDifferentUrl(): void
    {
        $generator = $this->generator();

        self::assertNotSame(
            $generator->url(self::KEY, ImageSize::Listing, 384, ImageFormat::Webp),
            $generator->url(self::KEY, ImageSize::Listing, 512, ImageFormat::Webp),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function zoomWidths(): iterable
    {
        yield '1280' => [1280];
        yield '1920' => [1920];
        yield '2560' => [2560];
    }

    #[DataProvider('zoomWidths')]
    public function testZoomUrlFitsTheWholeImageWithAutomaticHeightAsWebp(int $width): void
    {
        $url = $this->generator()->url(self::KEY, ImageSize::Zoom, $width, ImageFormat::Webp);

        self::assertStringContainsString(\sprintf('/rs:fit:%d:0/', $width), $url);
        self::assertStringNotContainsString('/el:', $url);
        self::assertStringEndsWith('.webp', $url);
    }

    #[DataProvider('zoomWidths')]
    public function testZoomUrlIsSignedLikeTheOtherSizes(int $width): void
    {
        $generator = $this->generator();
        $url = $generator->url(self::KEY, ImageSize::Zoom, $width, ImageFormat::Webp);

        $path = substr($url, \strlen('http://localhost:8081'));
        $signature = explode('/', $path)[1];

        self::assertSame($generator->sign(substr($path, \strlen($signature) + 1)), $signature);
        $encoded = substr($url, strrpos($url, '/') + 1, -\strlen('.webp'));
        self::assertSame('s3://product-images/'.self::KEY, base64_decode(strtr($encoded, '-_', '+/'), true));
    }

    public function testListingAndProductUrlsAreUnchanged(): void
    {
        $generator = $this->generator();

        self::assertStringContainsString('/rs:fill:768:768/g:sm/', $generator->url(self::KEY, ImageSize::Listing, 768, ImageFormat::Webp));
        self::assertStringContainsString('/rs:fill:1600:1600/g:sm/', $generator->url(self::KEY, ImageSize::Product, 1600, ImageFormat::Webp));
        self::assertStringContainsString('/rs:fill:192:192/g:sm/', $generator->url(self::KEY, ImageSize::Thumbnail, 192, ImageFormat::Webp));
    }

    public function testWidthOfAnotherSizeIsRejectedForZoom(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->url(self::KEY, ImageSize::Zoom, 1600, ImageFormat::Webp);
    }

    public function testWidthOutsideTheSizeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->url(self::KEY, ImageSize::Listing, 300, ImageFormat::Webp);
    }

    public function testInvalidImageKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->url('../x.jpg', ImageSize::Listing, 384, ImageFormat::Webp);
    }

    private function generator(): ImgproxyUrlGenerator
    {
        return new ImgproxyUrlGenerator('http://localhost:8081', '736563726574', '68656C6C6F', 'product-images');
    }
}
