<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Catalog\Dictionaries;

/**
 * A small pool for the tests of the photo steps: the 37 photos of fixtures/images spread over the photo groups (every
 * group gets one, the first 17 groups a second one), written to a directory, with the manifest that describes them. A
 * salt is appended to every file (a JPEG stays a JPEG), so the files have sums no other test has stored.
 */
final class PoolFixture
{
    private function __construct()
    {
    }

    /**
     * @return array{version: int, key: string, files: list<array<string, string>>}
     */
    public static function write(string $directory, Dictionaries $dictionaries, int $version = 1, string $salt = ''): array
    {
        $images = glob(\dirname(__DIR__, 3).'/fixtures/images/*.jpg') ?: [];
        sort($images);
        $groups = $dictionaries->photoGroupNames();
        $files = [];
        foreach ($groups as $index => $group) {
            foreach ([$index, \count($groups) + $index] as $number => $image) {
                if (!isset($images[$image])) {
                    continue;
                }
                $name = \sprintf('%s-%d.jpg', $group, $number + 1);
                $contents = file_get_contents($images[$image]).$salt;
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
     * Removes the files of a directory and the directory itself.
     */
    public static function remove(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            is_dir($path) ? self::remove($path) : unlink($path);
        }
        is_dir($directory) && rmdir($directory);
    }
}
