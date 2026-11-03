<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

use App\Cart\Application\ProductData;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;

/**
 * The one place of the rules of the line states and of the totals of a cart.
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
            $productId = $line->productId();
            $summary = $data->summary($productId);
            $price = $data->price($productId);
            $available = $data->available($productId);
            $quantity = $line->quantity()->value();
            $state = match (true) {
                null === $summary => CartLineState::UnavailableProduct,
                null === $price => CartLineState::NoPrice,
                0 === $available => CartLineState::OutOfStock,
                $quantity > $available => CartLineState::InsufficientStock,
                default => CartLineState::Purchasable,
            };

            $lineTotal = null;
            if (CartLineState::Purchasable === $state) {
                $lineTotal = $price->multiply($quantity);
                $gross = $gross->add($lineTotal);
            } else {
                $excluded = true;
            }
            $itemCount += $quantity;

            $views[] = new CartLineView(
                $productId->toString(),
                null === $summary ? null : new CartLineProduct($summary->name, $summary->image),
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
