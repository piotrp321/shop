<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * A line of an order: the variant with copies of its product name, label and unit price at the moment the order was
 * placed, and 1 to 99 units. The values of the line are the unit price times the quantity.
 */
final readonly class OrderLine
{
    public const int MAX_QUANTITY = 99;
    public const int MAX_NAME_LENGTH = 255;

    private function __construct(
        private VariantId $variantId,
        private ProductId $productId,
        private string $productName,
        private string $variantLabel,
        private int $quantity,
        private PriceCopy $unitPrice,
    ) {
    }

    /**
     * @param string $variantLabel empty for the default variant of a product
     *
     * @throws InvalidOrder
     */
    public static function of(VariantId $variantId, ProductId $productId, string $productName, string $variantLabel, int $quantity, PriceCopy $unitPrice): self
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidOrder(\sprintf('A line has 1 to %d units.', self::MAX_QUANTITY));
        }
        $nameLength = mb_strlen($productName);
        if ($nameLength < 1 || $nameLength > self::MAX_NAME_LENGTH || mb_strlen($variantLabel) > self::MAX_NAME_LENGTH) {
            throw new InvalidOrder(\sprintf('A product name has 1 to %1$d characters, a variant label at most %1$d.', self::MAX_NAME_LENGTH));
        }

        return new self($variantId, $productId, $productName, $variantLabel, $quantity, $unitPrice);
    }

    public function variantId(): VariantId
    {
        return $this->variantId;
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function productName(): string
    {
        return $this->productName;
    }

    public function variantLabel(): string
    {
        return $this->variantLabel;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): PriceCopy
    {
        return $this->unitPrice;
    }

    public function lineNet(): Money
    {
        return $this->unitPrice->net()->times($this->quantity);
    }

    public function lineVat(): Money
    {
        return $this->unitPrice->vat()->times($this->quantity);
    }

    public function lineGross(): Money
    {
        return $this->unitPrice->gross()->times($this->quantity);
    }
}
