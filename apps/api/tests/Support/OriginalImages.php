<?php

declare(strict_types=1);

namespace App\Tests\Support;

use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;

/**
 * Puts an original under a given key in the test bucket of Garage, so that a product write which names the key passes
 * the check that the original exists. The content is not an image: the check looks at the key only.
 */
final class OriginalImages
{
    public static function exist(ContainerInterface $container, string ...$keys): void
    {
        $bucket = $container->get('test.product_images.storage');
        \assert($bucket instanceof FilesystemOperator);
        foreach ($keys as $key) {
            $bucket->write($key, 'original');
        }
    }
}
