<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Catalog\Dictionaries;

/**
 * A small pool for the tests of the photo steps: 37 tiny JPEG files (a header with readable dimensions, 8x8 px) spread
 * over the photo groups (every group gets one, the first 17 groups a second one), written to a directory, with the manifest
 * that describes them. The files are not real photos, so a pool of any number of them is cheap in memory and on disk. Every
 * file has its own marker and the salt appended after the end of the image (a JPEG stays a JPEG), so the files have sums
 * no other test has stored.
 */
final class PoolFixture
{
    /** SOI, a JFIF header, a baseline frame of 8x8 px with one component, EOI. */
    private const string JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        ."\xFF\xC0\x00\x0B\x08\x00\x08\x00\x08\x01\x01\x11\x00"
        ."\xFF\xD9";

    private function __construct()
    {
    }

    /**
     * @return array{version: int, key: string, files: list<array<string, string>>}
     */
    public static function write(string $directory, Dictionaries $dictionaries, int $version = 1, string $salt = ''): array
    {
        $files = [];
        foreach ($dictionaries->photoGroupNames() as $index => $group) {
            foreach ([0, 1] as $number) {
                if (1 === $number && $index >= 17) {
                    continue;
                }
                $name = \sprintf('%s-%d.jpg', $group, $number + 1);
                $contents = self::JPEG.$name.$salt;
                file_put_contents($directory.'/'.$name, $contents);
                $files[] = [
                    'file' => $name,
                    'sha256' => hash('sha256', $contents),
                    'group' => $group,
                    'alt' => 'Zdjęcie grupy '.$group.' nr '.($number + 1),
                    'unsplashId' => \sprintf('pool%02d%02d', $index, $number),
                    'source' => \sprintf('https://unsplash.com/photos/pool%02d%02d', $index, $number),
                    'author' => 'Autor Łukasz',
                    'license' => 'Unsplash License',
                ];
            }
        }

        return ['version' => $version, 'key' => \sprintf('demo-photos/v%d/photos.tar', $version), 'files' => $files];
    }

    /**
     * Removes a directory with everything in it (a link is removed, never followed).
     */
    public static function remove(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $path = $directory.'/'.$name;
            is_dir($path) && !is_link($path) ? self::remove($path) : unlink($path);
        }
        rmdir($directory);
    }
}
