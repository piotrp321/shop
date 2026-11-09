<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * A line of an order: the variant with copies of its product name, label, unit price and promotion at the moment the
 * order was placed, and 1 to 99 units. The values of the line are copied from the quote of Pricing (decisions A2, D8):
 * the gross before the discount is the unit gross times the quantity, the gross is that less the line's share of the
 * discount of the code, and net + vat = gross (computed by Pricing from the gross of the line).
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
        private LineAmounts $amounts,
        private ?PromotionCopy $promotion,
    ) {
    }

    /**
     * @param string             $variantLabel empty for the default variant of a product
     * @param LineAmounts        $amounts      the discount, net, VAT and gross of the line (stored ones when restored)
     * @param PromotionCopy|null $promotion    the promotion that gave the unit price, if any
     *
     * @throws InvalidOrder
     */
    public static function of(VariantId $variantId, ProductId $productId, string $productName, string $variantLabel, int $quantity, PriceCopy $unitPrice, LineAmounts $amounts, ?PromotionCopy $promotion): self
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidOrder(\sprintf('A line has 1 to %d units.', self::MAX_QUANTITY));
        }
        $nameLength = mb_strlen($productName);
        if ($nameLength < 1 || $nameLength > self::MAX_NAME_LENGTH || mb_strlen($variantLabel) > self::MAX_NAME_LENGTH) {
            throw new InvalidOrder(\sprintf('A product name has 1 to %1$d characters, a variant label at most %1$d.', self::MAX_NAME_LENGTH));
        }

        if ($amounts->gross()->amount() + $amounts->discount()->amount() !== $unitPrice->gross()->amount() * $quantity) {
            throw new InvalidOrder('The gross of a line is the unit gross times the quantity less its discount.');
        }

        return new self($variantId, $productId, $productName, $variantLabel, $quantity, $unitPrice, $amounts, $promotion);
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

    public function promotion(): ?PromotionCopy
    {
        return $this->promotion;
    }

    /**
     * The unit gross (the effective price) times the quantity.
     */
    public function grossBeforeDiscount(): Money
    {
        return $this->unitPrice->gross()->times($this->quantity);
    }

    /**
     * The share of the line in the discount of the code; 0 without a code.
     */
    public function discount(): Money
    {
        return $this->amounts->discount();
    }

    public function lineNet(): Money
    {
        return $this->amounts->net();
    }

    public function lineVat(): Money
    {
        return $this->amounts->vat();
    }

    /**
     * After the discount.
     */
    public function lineGross(): Money
    {
        return $this->amounts->gross();
    }
}
