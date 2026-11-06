<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * A variant as stored in the search index. `options` pairs each axis with the variant's value as
 * "<axis>" U+001F "<value>", exactly as in Catalog (case kept); the default variant of a product without axes has
 * neither axes nor options.
 */
final readonly class VariantDocument
{
    public const string OPTION_SEPARATOR = "\u{1F}";

    /**
     * @param int|null     $gross   the current gross price in grosze, null without one
     * @param list<string> $axes    the names of the product's axes, in order
     * @param list<string> $options "<axis>" U+001F "<value>" for each axis, in the order of the axes
     */
    public function __construct(
        public ?int $gross,
        public bool $purchasable,
        public array $axes,
        public array $options,
    ) {
    }
}
