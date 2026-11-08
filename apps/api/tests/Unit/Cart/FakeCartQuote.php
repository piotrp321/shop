<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\CartQuote;
use App\Cart\Application\Port\CartQuoteLine;
use App\Cart\Application\Port\CartQuoteResult;
use App\Cart\Application\Port\QuotedCartLine;
use App\Cart\Application\View\AppliedCartDiscount;
use App\Cart\Application\View\RejectedCartDiscount;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;

/**
 * Pricing's quote as Cart's port sees it: unit prices of its own (so a test sees whether a value comes from the quote
 * or from CartPrices), a fixed discount, and the calls it got.
 */
final class FakeCartQuote implements CartQuote
{
    /** @var array<string, int> unit gross by variant id */
    private array $units = [];
    private int $discountGross = 0;
    private AppliedCartDiscount|RejectedCartDiscount|null $discount = null;
    /** @var list<array{list<array{string, int}>, ?string}> the lines (variant id, units) and the code of every call */
    public array $calls = [];

    public function price(string $variantId, int $unitGross): self
    {
        $this->units[$variantId] = $unitGross;

        return $this;
    }

    public function discount(AppliedCartDiscount|RejectedCartDiscount $discount, int $amount = 0): self
    {
        $this->discount = $discount;
        $this->discountGross = $amount;

        return $this;
    }

    public function quote(array $lines, ?string $discountCode): CartQuoteResult
    {
        $this->calls[] = [array_map(static fn (CartQuoteLine $line): array => [$line->variantId->toString(), $line->quantity], $lines), $discountCode];
        $quoted = [];
        $subtotal = 0;
        foreach ($lines as $line) {
            $unit = $this->units[$line->variantId->toString()] ?? null;
            if (null !== $unit) {
                $quoted[$line->variantId->toString()] = new QuotedCartLine(Money::of($unit, Currency::PLN), Money::of($unit * $line->quantity, Currency::PLN));
                $subtotal += $unit * $line->quantity;
            }
        }

        return new CartQuoteResult(
            $quoted,
            Money::of($subtotal, Currency::PLN),
            Money::of($this->discountGross, Currency::PLN),
            Money::of($subtotal - $this->discountGross, Currency::PLN),
            $this->discount,
        );
    }
}
