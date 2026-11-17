<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use App\Catalog\Infrastructure\Image\InvalidImage;
use App\DemoData\Photo\PhotoRules;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules a photo of the pool has to meet, the ones of the upload in Catalog (FlysystemProductImageStorage): the
 * contract test gives both the same contents and limits and expects the same verdict.
 */
final class PhotoRulesTest extends TestCase
{
    private const int MAX_BYTES = 67108864;
    private const int MAX_PIXELS = 50000000;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/photo-rules-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,originals/}*', \GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->dir.'/originals') && rmdir($this->dir.'/originals');
        rmdir($this->dir);
    }

    public function testAPhotoOfTheFixturesPasses(): void
    {
        $rules = new PhotoRules(self::MAX_BYTES, self::MAX_PIXELS);

        self::assertNull($rules->violationOfContents(self::jpeg()));
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function refusedContents(): iterable
    {
        yield 'text' => ['This is not an image.', self::MAX_BYTES, self::MAX_PIXELS, 'image type'];
        yield 'an empty file' => ['', self::MAX_BYTES, self::MAX_PIXELS, 'image type'];
        yield 'a gif' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", self::MAX_BYTES, self::MAX_PIXELS, 'image type'];
        yield 'a jpeg that is cut off before any dimensions' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", self::MAX_BYTES, self::MAX_PIXELS, 'dimensions'];
        yield 'a png of 100 megapixels' => [self::pngHeader(10000, 10000), self::MAX_BYTES, self::MAX_PIXELS, 'pixels'];
        yield 'a png of zero width' => [self::pngHeader(0, 10), self::MAX_BYTES, self::MAX_PIXELS, 'dimensions'];
        yield 'a jpeg over the size limit' => [self::jpeg(), 1000, self::MAX_PIXELS, 'bytes'];
        yield 'a jpeg over the pixel limit' => [self::jpeg(), self::MAX_BYTES, 1000, 'pixels'];
    }

    #[DataProvider('refusedContents')]
    public function testAPhotoThatBreaksARuleIsRefusedWithTheReason(string $contents, int $maxBytes, int $maxPixels, string $reason): void
    {
        $violation = new PhotoRules($maxBytes, $maxPixels)->violationOfContents($contents);

        self::assertNotNull($violation);
        self::assertStringContainsString($reason, $violation);
    }

    #[DataProvider('refusedContents')]
    public function testTheCatalogUploadRefusesWhatTheRulesRefuse(string $contents, int $maxBytes, int $maxPixels, string $reason): void
    {
        self::assertNotNull(new PhotoRules($maxBytes, $maxPixels)->violationOfContents($contents), $reason);
        $this->assertSameVerdict($contents, $maxBytes, $maxPixels);
    }

    public function testTheCatalogUploadAcceptsWhatTheRulesAccept(): void
    {
        $this->assertSameVerdict(self::jpeg(), self::MAX_BYTES, self::MAX_PIXELS);
        $this->assertSameVerdict(self::pngHeader(1000, 1000), self::MAX_BYTES, self::MAX_PIXELS);
    }

    public function testAFileIsCheckedByItsSizeBeforeItIsRead(): void
    {
        $path = $this->dir.'/big.jpg';
        file_put_contents($path, self::jpeg());
        $handle = fopen($path, 'a');
        self::assertNotFalse($handle);
        ftruncate($handle, 70 * 1024 * 1024);
        fclose($handle);

        $violation = new PhotoRules(self::MAX_BYTES, self::MAX_PIXELS)->violationOfFile($path);

        self::assertNotNull($violation);
        self::assertStringContainsString('bytes', $violation);
    }

    public function testAFileThatCannotBeReadIsRefused(): void
    {
        $violation = new PhotoRules(self::MAX_BYTES, self::MAX_PIXELS)->violationOfFile($this->dir.'/missing.jpg');

        self::assertNotNull($violation);
        self::assertStringContainsString('read', $violation);
    }

    public function testAFileOfTheFixturesPasses(): void
    {
        $path = $this->dir.'/photo.jpg';
        file_put_contents($path, self::jpeg());

        self::assertNull(new PhotoRules(self::MAX_BYTES, self::MAX_PIXELS)->violationOfFile($path));
    }

    private function assertSameVerdict(string $contents, int $maxBytes, int $maxPixels): void
    {
        $violation = new PhotoRules($maxBytes, $maxPixels)->violationOfContents($contents);
        $storage = new FlysystemProductImageStorage(new Filesystem(new LocalFilesystemAdapter($this->dir)), $maxBytes, $maxPixels);

        try {
            $storage->store($contents);
            $accepted = true;
        } catch (InvalidImage) {
            $accepted = false;
        }

        self::assertSame($accepted, null === $violation, 'The rules say '.($violation ?? 'accepted').', the upload of Catalog says '.($accepted ? 'accepted' : 'refused').'.');
    }

    private static function jpeg(): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/fixtures/images/hiker-reading-map.jpg');
        self::assertIsString($contents);

        return $contents;
    }

    /** A PNG whose header says the size and that has no image data: enough for the type and the dimensions. */
    private static function pngHeader(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }
}
