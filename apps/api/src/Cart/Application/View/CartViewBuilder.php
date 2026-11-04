<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

use App\Cart\Application\ProductData;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\Purchasability;

/**
 * The one place of the totals of a cart and of the mapping of the line states. The rule of buying (price, then stock)
 * is only in ProductOffer::purchasability(); a variant unknown to Catalog is the only state decided here.
 */
final readonly class CartViewBuilder
{
    /**
     * @param list<CartLine> $lines in the order they were added
     */
    public function build(?CartId $id, array $lines, ProductData $data): CartView
    {
        $views = [];
        $gross = Money::zero(Currency::PLN);
        $itemCount = 0;
        $excluded = false;
        foreach ($lines as $line) {
            $variantId = $line->variantId();
            $summary = $data->summary($variantId);
            $price = $data->price($variantId);
            $quantity = $line->quantity()->value();
            $state = null === $summary
                ? CartLineState::UnavailableProduct
                : match ($data->offerFor($variantId)->purchasability($quantity)) {
                    Purchasability::NoPrice => CartLineState::NoPrice,
                    Purchasability::OutOfStock => CartLineState::OutOfStock,
                    Purchasability::InsufficientStock => CartLineState::InsufficientStock,
                    Purchasability::Purchasable => CartLineState::Purchasable,
                };

            $lineTotal = null;
            if (CartLineState::Purchasable === $state) {
                $lineTotal = ($price ?? throw new \LogicException('A purchasable line has a current price.'))->multiply($quantity);
                $gross = $gross->add($lineTotal);
            } else {
                $excluded = true;
            }
            $itemCount += $quantity;

            $views[] = new CartLineView(
                $variantId->toString(),
                null === $summary ? null : new CartLineProduct($summary->productId->toString(), $summary->name, $summary->label, $summary->image),
                $quantity,
                null === $price ? null : GrossAmount::of($price),
                null === $lineTotal ? null : GrossAmount::of($lineTotal),
                $state->value,
            );
        }

        return new CartView($id?->toString(), $views, new CartTotals($gross->amount(), $gross->currency()->value, $itemCount), $excluded);
    }

    public function empty(?CartId $id): CartView
    {
        return $this->build($id, [], new ProductData([], [], []));
    }
}
