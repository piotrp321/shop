<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * A variant of a product: its id, its values and the optional codes the seller gives it. The codes belong to the variant
 * (its id), not to its values: they are not part of its identity and may change after the first publication.
 */
final readonly class ProductVariant
{
    public function __construct(
        private VariantId $id,
        private VariantValues $values,
        private ?Sku $sku = null,
        private ?Ean $ean = null,
    ) {
    }

    public function id(): VariantId
    {
        return $this->id;
    }

    public function values(): VariantValues
    {
        return $this->values;
    }

    public function sku(): ?Sku
    {
        return $this->sku;
    }

    public function ean(): ?Ean
    {
        return $this->ean;
    }

    /**
     * The one place that writes the label of a variant ("czarne, 42"); empty for the default variant.
     */
    public function label(): string
    {
        return $this->values->label();
    }
}
