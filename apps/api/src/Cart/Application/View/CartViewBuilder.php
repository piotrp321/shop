<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

use App\Cart\Application\Port\CartQuote;
use App\Cart\Application\Port\CartQuoteLine;
use App\Cart\Application\ProductData;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Purchasability;

/**
 * The one place of the mapping of the line states and of the values of a cart. The rule of buying (price, then stock)
 * is only in ProductOffer::purchasability(), with the prices of CartPrices; a variant unknown to Catalog is the only
 * state decided here. The unit prices, line values and totals of the purchasable lines come only from Pricing's quote
 * of those lines (decision D9), with the code when one is given; a cart without a purchasable line is not quoted.
 */
final readonly class CartViewBuilder
{
    public function __construct(private CartQuote $quote)
    {
    }

    /**
     * @param list<CartLine> $lines        in the order they were added
     * @param string|null    $discountCode the code of the preview, as typed
     */
    public function build(?CartId $id, array $lines, ProductData $data, ?string $discountCode = null): CartView
    {
        $states = [];
        $purchasable = [];
        $itemCount = 0;
        foreach ($lines as $line) {
            $variantId = $line->variantId();
            $quantity = $line->quantity()->value();
            $state = null === $data->summary($variantId)
                ? CartLineState::UnavailableProduct
                : match ($data->offerFor($variantId)->purchasability($quantity)) {
                    Purchasability::NoPrice => CartLineState::NoPrice,
                    Purchasability::OutOfStock => CartLineState::OutOfStock,
                    Purchasability::InsufficientStock => CartLineState::InsufficientStock,
                    Purchasability::Purchasable => CartLineState::Purchasable,
                };
            if (CartLineState::Purchasable === $state) {
                $purchasable[] = new CartQuoteLine($variantId, $quantity);
            }
            $states[] = $state;
            $itemCount += $quantity;
        }
        $quote = [] === $purchasable ? null : $this->quote->quote($purchasable, $discountCode);

        $views = [];
        $excluded = false;
        foreach ($lines as $index => $line) {
            $variantId = $line->variantId();
            $summary = $data->summary($variantId);
            $price = $data->price($variantId);
            $state = $states[$index];
            $lineTotal = null;
            if (CartLineState::Purchasable === $state) {
                $quoted = $quote?->lines[$variantId->toString()] ?? null;
                // Purchasable by CartPrices but without an effective price in the quote, read a moment later.
                if (null === $quoted) {
                    $state = CartLineState::NoPrice;
                    $price = null;
                } else {
                    $price = $quoted->unitGross;
                    $lineTotal = $quoted->grossBeforeDiscount;
                }
            }
            $excluded = $excluded || CartLineState::Purchasable !== $state;

            $views[] = new CartLineView(
                $variantId->toString(),
                null === $summary ? null : new CartLineProduct($summary->productId->toString(), $summary->name, $summary->label, $summary->image),
                $line->quantity()->value(),
                null === $price ? null : GrossAmount::of($price),
                null === $lineTotal ? null : GrossAmount::of($lineTotal),
                $state->value,
            );
        }

        return new CartView(
            $id?->toString(),
            $views,
            new CartTotals(
                $quote?->subtotalGross->amount() ?? 0,
                $quote?->discountGross->amount() ?? 0,
                $quote?->gross->amount() ?? 0,
                Currency::PLN->value,
                $itemCount,
            ),
            $excluded,
            $quote?->discount,
        );
    }

    public function empty(?CartId $id): CartView
    {
        return $this->build($id, [], new ProductData([], [], []));
    }
}
