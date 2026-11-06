<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Cache;

/**
 * The tag dictionary of the HTTP caches of the store (Varnish xkey; later also the ISR pages): the only place that
 * builds tag names, so the tags of a response and the tags of an invalidation cannot drift apart.
 */
final class CacheTags
{
    /** Every catalog read. */
    public const string CATALOG = 'catalog';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Every response that contains data of the product.
     *
     * @throws \InvalidArgumentException when the id is not a UUID
     */
    public static function product(string $productId): string
    {
        if (1 !== preg_match(self::UUID, $productId)) {
            throw new \InvalidArgumentException('A product tag needs the UUID of the product.');
        }

        return 'product-'.strtolower($productId);
    }
}
