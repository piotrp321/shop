<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Photo\InvalidPhotoPool;
use App\DemoData\Photo\TarExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The extraction of the archive of the pool: flat regular files by the names of the manifest, nothing else, nothing
 * outside the directory.
 */
final class TarExtractorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tar-extractor-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/out', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,out/}*', \GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        rmdir($this->dir.'/out');
        rmdir($this->dir);
    }

    public function testTheFilesOfTheNamesAreExtractedWithTheirContents(): void
    {
        $this->extract(TarBuilder::archive([
            TarBuilder::entry('a.jpg', 'first'),
            TarBuilder::entry('b.jpg', str_repeat('x', 1500)),
            TarBuilder::entry('empty.jpg', ''),
        ]), ['a.jpg', 'b.jpg', 'empty.jpg']);

        self::assertSame('first', file_get_contents($this->dir.'/out/a.jpg'));
        self::assertSame(str_repeat('x', 1500), file_get_contents($this->dir.'/out/b.jpg'));
        self::assertSame('', file_get_contents($this->dir.'/out/empty.jpg'));
    }

    public function testAnArchiveOfPharDataIsRead(): void
    {
        file_put_contents($this->dir.'/a.jpg', 'first');
        file_put_contents($this->dir.'/b.jpg', 'second');
        $phar = new \PharData($this->dir.'/made.tar');
        $phar->addFile($this->dir.'/a.jpg', 'a.jpg');
        $phar->addFile($this->dir.'/b.jpg', 'b.jpg');
        unset($phar);

        (new TarExtractor(1000))->extract($this->dir.'/made.tar', $this->dir.'/out', ['a.jpg', 'b.jpg']);

        self::assertSame('first', file_get_contents($this->dir.'/out/a.jpg'));
        self::assertSame('second', file_get_contents($this->dir.'/out/b.jpg'));
        unlink($this->dir.'/a.jpg');
        unlink($this->dir.'/b.jpg');
        unlink($this->dir.'/made.tar');
    }

    public function testAnArchiveMadeByGnuTarIsRead(): void
    {
        file_put_contents($this->dir.'/a.jpg', 'first');
        exec(\sprintf('tar --format=ustar -cf %s -C %s a.jpg 2>&1', escapeshellarg($this->dir.'/made.tar'), escapeshellarg($this->dir)), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        (new TarExtractor(1000))->extract($this->dir.'/made.tar', $this->dir.'/out', ['a.jpg']);

        self::assertSame('first', file_get_contents($this->dir.'/out/a.jpg'));
        unlink($this->dir.'/a.jpg');
        unlink($this->dir.'/made.tar');
    }

    /**
     * @param list<string> $names
     */
    private function extract(string $archive, array $names, int $maxBytes = 10000): void
    {
        file_put_contents($this->dir.'/photos.tar', $archive);
        try {
            (new TarExtractor($maxBytes))->extract($this->dir.'/photos.tar', $this->dir.'/out', $names);
        } finally {
            unlink($this->dir.'/photos.tar');
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function refusedArchives(): iterable
    {
        yield 'an entry that is not in the manifest' => [TarBuilder::archive([TarBuilder::entry('a.jpg', 'x'), TarBuilder::entry('other.jpg', 'x')]), ['a.jpg'], 'other.jpg: not in the manifest'];
        yield 'an entry in a directory' => [TarBuilder::archive([TarBuilder::entry('sub/a.jpg', 'x')]), ['a.jpg'], 'not in the manifest'];
        yield 'a path leaving the directory' => [TarBuilder::archive([TarBuilder::entry('../a.jpg', 'x')]), ['a.jpg'], 'not in the manifest'];
        yield 'an absolute path' => [TarBuilder::archive([TarBuilder::entry('/tmp/a.jpg', 'x')]), ['a.jpg'], 'not in the manifest'];
        yield 'a symbolic link' => [TarBuilder::archive([TarBuilder::entry('a.jpg', '', '2')]), ['a.jpg'], 'a.jpg: only regular files'];
        yield 'a hard link' => [TarBuilder::archive([TarBuilder::entry('a.jpg', '', '1')]), ['a.jpg'], 'a.jpg: only regular files'];
        yield 'a pax header' => [TarBuilder::archive([TarBuilder::entry('PaxHeader', "30 mtime=1\n", 'x'), TarBuilder::entry('a.jpg', 'x')]), ['a.jpg'], 'only regular files'];
        yield 'a repeated entry' => [TarBuilder::archive([TarBuilder::entry('a.jpg', 'x'), TarBuilder::entry('a.jpg', 'y')]), ['a.jpg'], 'a.jpg: repeated'];
        yield 'a broken checksum' => [TarBuilder::archive([TarBuilder::entry('a.jpg', 'x', '0', false)]), ['a.jpg'], 'checksum'];
        yield 'a file cut off in the middle' => [substr(TarBuilder::archive([TarBuilder::entry('a.jpg', str_repeat('x', 3000))], false), 0, 1500), ['a.jpg'], 'cut off'];
        yield 'a file larger than the limit' => [TarBuilder::archive([TarBuilder::entry('a.jpg', str_repeat('x', 20000))]), ['a.jpg'], 'a.jpg: larger than 10000 bytes'];
        yield 'text that is not an archive' => [str_repeat('this is not a tar archive ', 100), ['a.jpg'], 'not a tar archive'];
        yield 'an empty file' => ['', ['a.jpg'], 'not a tar archive'];
        yield 'a header cut off' => [str_repeat("\1", 100), ['a.jpg'], 'not a tar archive'];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('refusedArchives')]
    public function testAnArchiveThatBreaksARuleIsRefusedWithTheReason(string $archive, array $names, string $reason): void
    {
        try {
            $this->extract($archive, $names);
            self::fail('The archive should have been refused.');
        } catch (InvalidPhotoPool $e) {
            self::assertStringContainsString($reason, implode(' | ', $e->violations));
        }
    }

    public function testNothingOutsideTheDirectoryIsWrittenForAPathThatLeavesIt(): void
    {
        try {
            $this->extract(TarBuilder::archive([TarBuilder::entry('../evil.jpg', 'x')]), ['evil.jpg']);
        } catch (InvalidPhotoPool) {
        }

        self::assertFileDoesNotExist($this->dir.'/evil.jpg');
        self::assertFileDoesNotExist($this->dir.'/out/evil.jpg');
    }

    public function testAMissingArchiveIsRefused(): void
    {
        $this->expectException(InvalidPhotoPool::class);

        (new TarExtractor(1000))->extract($this->dir.'/nonexistent.tar', $this->dir.'/out', ['a.jpg']);
    }

    public function testAnArchiveWithoutTheNamesGivesAnEmptyDirectory(): void
    {
        $this->extract(TarBuilder::archive([]), ['a.jpg']);

        self::assertSame([], glob($this->dir.'/out/*'));
    }
}
