<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Image;

trait GuardsTestBucket
{
    /**
     * Fails fast on a stale or misconfigured container instead of purging the dev bucket.
     */
    private static function assertUsesTestBucket(): void
    {
        self::assertSame('product-images-test', self::getContainer()->getParameter('app.images_bucket'));
    }
}
