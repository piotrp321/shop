<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ProductVariant
{
    public function __construct(
        private VariantId $id,
        private VariantValues $values,
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

    /**
     * The one place that writes the label of a variant ("czarne, 42"); empty for the default variant.
     */
    public function label(): string
    {
        return $this->values->label();
    }
}
