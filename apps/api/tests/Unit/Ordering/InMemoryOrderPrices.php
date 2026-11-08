<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderPrices;
use App\Ordering\Application\Port\OrderQuote;
use App\Ordering\Application\Port\QuotedCodeRejection;
use App\Ordering\Application\Port\QuotedOrderLine;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\Money;
use App\Ordering\Domain\OrderDiscount;
use App\Ordering\Domain\OrderDiscountKind;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\PromotionCopy;

/**
 * A quote like Pricing's, simpler: the effective unit prices as set, a code applied by its text (the percent of the
 * subtotal rounded down, or its amount) and taken off the lines in their order, each down to zero at most, and the
 * VAT of a line computed from its gross. A code with a rejection is rejected; an unknown code is no code at all.
 */
final class InMemoryOrderPrices implements OrderPrices
{
    /** @var array<string, PriceCopy> */
    private array $prices = [];
    /** @var array<string, PromotionCopy> */
    private array $promotions = [];
    /** @var array<string, array{string, OrderDiscountKind, int}> codeId, kind and value by text */
    private array $codes = [];
    /** @var array<string, QuotedCodeRejection> by text */
    private array $rejections = [];
    /** @var list<list<string>> */
    public array $asked = [];
    /** @var list<?string> the code of each quote */
    public array $askedCodes = [];
    public ?OrderQuote $lastQuote = null;

    public function __construct(private readonly Journal $journal)
    {
    }

    public function price(string $variantId, int $net, int $vat, int $vatRateBp = 2300, string $taxClass = 'standard'): self
    {
        $this->prices[$variantId] = PriceCopy::of($net, $vat, $net + $vat, $vatRateBp, $taxClass, Currency::PLN);

        return $this;
    }

    public function promotion(string $variantId, PromotionCopy $promotion): self
    {
        $this->promotions[$variantId] = $promotion;

        return $this;
    }

    public function code(string $text, string $codeId, OrderDiscountKind $kind, int $value): self
    {
        $this->codes[$text] = [$codeId, $kind, $value];

        return $this;
    }

    public function reject(string $text, string $type, string $detail, ?int $minimumOrderGross = null): self
    {
        $this->rejections[$text] = new QuotedCodeRejection($type, $detail, $minimumOrderGross);

        return $this;
    }

    public function quote(array $lines, ?string $discountCode): OrderQuote
    {
        $this->journal->record('prices');
        $this->asked[] = array_map(static fn ($line): string => $line->variantId->toString(), $lines);
        $this->askedCodes[] = $discountCode;

        $priced = [];
        $subtotal = 0;
        foreach ($lines as $line) {
            $price = $this->prices[$line->variantId->toString()] ?? null;
            if (null !== $price) {
                $priced[$line->variantId->toString()] = [$price, $line->quantity];
                $subtotal += $price->gross()->amount() * $line->quantity;
            }
        }

        $code = null === $discountCode ? null : ($this->codes[$discountCode] ?? null);
        $rejection = null === $discountCode ? null : ($this->rejections[$discountCode] ?? null);
        $discount = null === $code || null !== $rejection ? 0 : (OrderDiscountKind::Percent === $code[1] ? intdiv($subtotal * $code[2], 100) : $code[2]);

        $quoted = [];
        $left = $discount;
        foreach ($priced as $variantId => [$price, $quantity]) {
            $before = $price->gross()->amount() * $quantity;
            $lineDiscount = min($left, $before);
            $left -= $lineDiscount;
            $gross = $before - $lineDiscount;
            $amounts = 0 === $lineDiscount
                ? LineAmounts::of(0, $price->net()->amount() * $quantity, $price->vat()->amount() * $quantity, $before, Currency::PLN)
                : LineAmounts::of($lineDiscount, $gross - intdiv($gross * $price->vatRateBp(), 10_000 + $price->vatRateBp()), intdiv($gross * $price->vatRateBp(), 10_000 + $price->vatRateBp()), $gross, Currency::PLN);
            $quoted[$variantId] = new QuotedOrderLine($price, $amounts, $this->promotions[$variantId] ?? null);
        }

        $applied = null;
        $codeId = null;
        if (null !== $discountCode && null !== $code && $discount > 0) {
            $applied = OrderDiscount::of($discountCode, $code[1], $code[2], Money::of($discount, Currency::PLN));
            $codeId = $code[0];
        }

        return $this->lastQuote = new OrderQuote($quoted, $subtotal, $applied, $codeId, $rejection);
    }
}
