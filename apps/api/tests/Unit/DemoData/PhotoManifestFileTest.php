<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Photo\PhotoManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest committed to the repository: it follows the schema, covers every photo group, has the size of the pool and holds
 * no value of the variables of the access to the private bucket.
 */
final class PhotoManifestFileTest extends TestCase
{
    private const string FILE = __DIR__.'/../../../fixtures/demo-data/photos.yaml';
    private const array VARIABLES = [
        'DEMO_PHOTOS_ENDPOINT', 'DEMO_PHOTOS_BUCKET', 'DEMO_PHOTOS_ACCESS_KEY_ID', 'DEMO_PHOTOS_SECRET_ACCESS_KEY',
        'DEMO_PHOTOS_WRITE_ACCESS_KEY_ID', 'DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY',
    ];

    public function testTheManifestOfTheRepositoryFollowsTheSchemaAndCoversEveryPhotoGroup(): void
    {
        $groups = new Dictionaries(\dirname(self::FILE))->photoGroupNames();

        $manifest = PhotoManifest::fromFile(self::FILE, $groups);

        self::assertSame([], $manifest->groupsWithoutFiles($groups));
    }

    public function testThePoolHoldsAboutFourThousandFilesAndEveryGroupHasHundredsOfThem(): void
    {
        $groups = new Dictionaries(\dirname(self::FILE))->photoGroupNames();

        $manifest = PhotoManifest::fromFile(self::FILE, $groups);

        // The pool is 1142 photos with their variants (about 4000 files, spec: Zdjęcia); the margin lets a few photos be
        // swapped without touching the test, but a pool that lost its variants or a group does not pass.
        self::assertGreaterThanOrEqual(3500, \count($manifest->files));
        self::assertLessThanOrEqual(4500, \count($manifest->files));
        foreach ($manifest->fileNamesByGroup() as $group => $files) {
            self::assertGreaterThanOrEqual(100, \count($files), $group);
            self::assertLessThanOrEqual(300, \count($files), $group);
        }
    }

    public function testNoPairOfTheSourceIdAndTheVariantIsRepeatedAndEveryPhotoHasItsOriginal(): void
    {
        $manifest = PhotoManifest::fromFile(self::FILE, new Dictionaries(\dirname(self::FILE))->photoGroupNames());

        $pairs = [];
        $originals = [];
        $sources = [];
        foreach ($manifest->files as $entry) {
            $pairs[] = $entry->sourceId.' '.$entry->variant;
            if ('original' === $entry->variant) {
                $originals[$entry->sourceId] = true;
            }
            $sources[$entry->sourceId] = true;
        }

        self::assertCount(\count($pairs), array_unique($pairs));
        self::assertSame(array_keys($sources), array_keys($originals), 'A photo with variants but without its original.');
        self::assertGreaterThan(1000, \count($sources));
    }

    public function testTheManifestOfTheRepositoryNeverHoldsTheVariablesOrTheirValues(): void
    {
        $text = (string) file_get_contents(self::FILE);

        self::assertStringNotContainsString('DEMO_PHOTOS_', $text);
        foreach (self::VARIABLES as $name) {
            $value = getenv($name);
            if (\is_string($value) && '' !== $value) {
                self::assertStringNotContainsString($value, $text, $name);
            }
        }
    }
}
