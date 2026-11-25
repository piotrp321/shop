<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

/**
 * The SKU and the EAN of a generated variant: functions of the 0-based index of the product and the 0-based position of
 * the variant, so they are unique by construction without a read of the table, the same whatever the part or the
 * number of processes, and apart from the SKUs of the seed (those begin with a letter, these with a digit).
 *
 * The SKU is the number of the product (the index + 1, the number its slug ends with) padded with zeros to 8 digits, a
 * hyphen and the number of the variant (the position + 1): the rows of one part of the products get the SKUs of the
 * same stretch of the unique index. The EAN is EAN-13: the prefix 2, the number of the product (9 digits), the number
 * of the variant (2 digits) and the check digit of GS1. The prefix 2 is the pool of the numbers of restricted
 * circulation (GS1 General Specifications, 2.1.11), so no fictional product carries the code of a real one and the
 * store shows no `gtin` for it (docs/features/product-data/spec.md, D9).
 */
final class VariantCodes
{
    private const int MAX_PRODUCT_NUMBER = 999_999_999;
    private const int MAX_VARIANT_NUMBER = 99;

    private function __construct()
    {
    }

    public static function sku(int $index, int $position): string
    {
        return \sprintf('%08d-%d', $index + 1, $position + 1);
    }

    /**
     * @throws \InvalidArgumentException when the number of the product or of the variant does not fit the code
     */
    public static function ean(int $index, int $position): string
    {
        $product = $index + 1;
        $variant = $position + 1;
        if ($product < 1 || $product > self::MAX_PRODUCT_NUMBER || $variant < 1 || $variant > self::MAX_VARIANT_NUMBER) {
            throw new \InvalidArgumentException(\sprintf('An EAN-13 has no room for the product %d and the variant %d.', $product, $variant));
        }
        $payload = \sprintf('2%09d%02d', $product, $variant);

        return $payload.self::checkDigit($payload);
    }

    /**
     * The check digit of GS1 of the digits before it: the weights 3 and 1 alternate from the last digit, which has 3.
     */
    private static function checkDigit(string $payload): int
    {
        $sum = 0;
        $length = \strlen($payload);
        for ($position = 0; $position < $length; ++$position) {
            $sum += (int) $payload[$length - 1 - $position] * (0 === $position % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }
}
