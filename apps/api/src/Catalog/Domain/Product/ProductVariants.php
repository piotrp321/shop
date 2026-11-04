<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The variants of a product with the axes they are described by, in presentation order. A product has 1 to 100
 * variants; each has one value per axis; no two have the same combination of values (trimmed, without regard to
 * case); ids differ; without axes there is exactly one variant, the default one, without values.
 */
final readonly class ProductVariants
{
    public const int MAX_VARIANTS = 100;

    /**
     * @param list<ProductVariant> $variants
     */
    private function __construct(
        private VariantAxes $axes,
        private array $variants,
    ) {
    }

    /**
     * @param list<ProductVariant> $variants
     *
     * @throws InvalidProductVariants
     * @throws DuplicateVariantValues
     */
    public static function create(VariantAxes $axes, array $variants): self
    {
        $count = \count($variants);
        if ($count < 1 || $count > self::MAX_VARIANTS) {
            throw InvalidProductVariants::countOutOfRange();
        }
        foreach ($variants as $variant) {
            if ($variant->values()->count() !== $axes->count()) {
                throw InvalidProductVariants::valueCountDiffersFromAxes();
            }
        }
        if (0 === $axes->count() && 1 !== $count) {
            throw InvalidProductVariants::moreThanOneDefaultVariant();
        }

        $ids = [];
        $combinations = [];
        foreach ($variants as $variant) {
            $id = $variant->id()->toString();
            if (isset($ids[$id])) {
                throw InvalidProductVariants::idRepeated();
            }
            $ids[$id] = true;

            $combination = json_encode($variant->values()->comparisonKey(), \JSON_THROW_ON_ERROR);
            if (isset($combinations[$combination])) {
                throw new DuplicateVariantValues();
            }
            $combinations[$combination] = true;
        }

        return new self($axes, $variants);
    }

    public function axes(): VariantAxes
    {
        return $this->axes;
    }

    /**
     * @return list<ProductVariant> in presentation order
     */
    public function toList(): array
    {
        return $this->variants;
    }

    public function count(): int
    {
        return \count($this->variants);
    }
}
