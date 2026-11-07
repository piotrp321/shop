<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Cache;

/**
 * The tag dictionary of the HTTP caches of the store (Varnish xkey and the cache tags of the ISR pages): the only
 * place that builds tag names, so the tags of a response and the tags of an invalidation cannot drift apart.
 */
final class CacheTags
{
    /** Every catalog read. */
    public const string CATALOG = 'catalog';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    /** The format of Catalog's Slug (Shared cannot depend on Catalog); the store's /api/revalidate has the same one. */
    private const string SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';
    private const int SLUG_MAX_LENGTH = 200;
    /** Every tag built here: lower-case letters, digits and dashes. */
    private const string TAG = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

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

    /**
     * The 404 of a product page whose slug names no published product, so that the publication of a product with
     * the slug can drop it (decision A4).
     *
     * @throws \InvalidArgumentException when the slug is not in the format of a product slug
     */
    public static function productSlug(string $slug): string
    {
        if (\strlen($slug) > self::SLUG_MAX_LENGTH || 1 !== preg_match(self::SLUG, $slug)) {
            throw new \InvalidArgumentException('A product slug tag needs a slug of a product.');
        }

        return 'product-slug-'.$slug;
    }

    /**
     * Whether the text has the form of a tag built here; anything else (a space, a line break, an upper-case
     * letter) must never reach a header or a purge.
     */
    public static function isTag(string $tag): bool
    {
        return 1 === preg_match(self::TAG, $tag);
    }
}
