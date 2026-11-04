<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The key of a variant, `<slug of the product>/<slug of the label>` (the default variant: `<slug>/standard`), that
 * the demo data patterns (fixtures/prices.yaml, fixtures/stock.yaml) and the demo variant ids are built from. The
 * only place that writes it.
 */
final class VariantKey
{
    private const string DEFAULT_VARIANT = 'standard';

    private function __construct()
    {
    }

    public static function of(string $productSlug, VariantValues $values): string
    {
        $label = $values->label();

        return $productSlug.'/'.('' === $label ? self::DEFAULT_VARIANT : Slug::slugify($label));
    }
}
