<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use App\DemoData\Photo\PhotoRules;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The photo pool checks its files with the limits of the upload in Catalog: both services get them from the same parameters.
 */
final class PhotoRulesWiringTest extends KernelTestCase
{
    public function testThePhotoRulesHaveTheLimitsOfTheCatalogUpload(): void
    {
        $rules = self::getContainer()->get(PhotoRules::class);
        $storage = self::getContainer()->get(FlysystemProductImageStorage::class);
        self::assertInstanceOf(PhotoRules::class, $rules);
        self::assertInstanceOf(FlysystemProductImageStorage::class, $storage);

        $storageLimits = new \ReflectionObject($storage);

        self::assertSame($rules->maxBytes, $storageLimits->getProperty('maxBytes')->getValue($storage));
        self::assertSame($rules->maxPixels, $storageLimits->getProperty('maxPixels')->getValue($storage));
        self::assertSame(67108864, $rules->maxBytes);
        self::assertSame(50000000, $rules->maxPixels);
    }
}
