<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Photo\PhotoManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest committed to the repository (when there is one): it follows the schema, covers every photo group and holds
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
        if (!is_file(self::FILE)) {
            self::markTestSkipped('The pool of photos is not uploaded yet: there is no manifest.');
        }
        $groups = new Dictionaries(\dirname(self::FILE))->photoGroupNames();

        $manifest = PhotoManifest::fromFile(self::FILE, $groups);

        self::assertSame([], $manifest->groupsWithoutFiles($groups));
    }

    public function testTheManifestOfTheRepositoryNeverHoldsTheVariablesOrTheirValues(): void
    {
        if (!is_file(self::FILE)) {
            self::markTestSkipped('The pool of photos is not uploaded yet: there is no manifest.');
        }
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
