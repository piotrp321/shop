<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeRejection;
use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\PriceBreakdown;

/**
 * The one pricing of a basket (decisions D2, D8, D9, Q2, Q7): the cart view and the placement of an order take their
 * line values and totals from it.
 *
 * A line is worth its effective unit gross × units (g_i); the subtotal S is their sum. A code is checked against S
 * (DiscountCode::rejectionFor); a rejected one leaves the quote without discount, with the reason. The discount D of
 * a percent code is floor(S × p / 100), of an amount code its amount; Q7 and the minimum above the amount keep
 * 1 ≤ D < S. D is allocated by the largest remainders: d_i = floor(g_i × D / S), and the D − Σ d_i grosze left go one
 * each to the lines of the largest remainder g_i × D mod S, the earlier line of the basket first on a tie. So
 * Σ d_i = D and d_i ≤ g_i. The products g_i × D exceed 64 bits for a large basket (about 4.4·10^21 for 50 lines of 99
 * units at the largest price), so they are computed with ext-gmp; every result fits an int. Net and VAT of each line
 * come from its gross after the discount, g_i − d_i, at the rate of its price (PriceBreakdown::ofLineGross); the totals
 * are the sums of the lines.
 */
final readonly class BasketQuote
{
    public const int MAX_LINES = 50;

    /**
     * @param list<QuotedLine> $lines
     */
    private function __construct(
        private array $lines,
        private Money $subtotalGross,
        private Money $discountGross,
        private Money $net,
        private Money $vat,
        private Money $gross,
        private ?AppliedDiscount $discount,
        private ?DiscountCodeRejection $rejection,
    ) {
    }

    /**
     * A line without a price is left out of the quote (the cart marks it as not purchasable).
     *
     * @param list<BasketLine>           $lines      1 to 50 lines of distinct variants, in the order of the basket
     * @param array<string, BasketPrice> $prices     the effective prices by variant id
     * @param int                        $activeUses the active usages of the code (any number for a code without a limit)
     *
     * @throws \InvalidArgumentException when the basket has no line, more than 50 or a variant twice
     */
    public static function of(array $lines, array $prices, ?DiscountCode $code, int $activeUses, \DateTimeImmutable $now): self
    {
        $priced = self::priced($lines, $prices);
        $subtotal = array_sum(array_map(static fn (array $line): int => $line['gross'], $priced));
        if (null === $code) {
            return self::quote($priced, $subtotal, null, null);
        }
        $rejection = $code->rejectionFor($subtotal, $activeUses, $now);
        if (null !== $rejection) {
            return self::quote($priced, $subtotal, null, $rejection);
        }

        $value = $code->value();
        $percentOff = $value->percentOff();
        $amount = null === $percentOff
            ? ($value->amountOff()?->amount() ?? throw new \LogicException('A discount is a percent or an amount.'))
            // S × 90 stays far below the int range (S is at most 50 × 99 × GrossPrice::MAX).
            : intdiv($subtotal * $percentOff->value(), 100);

        return self::quote($priced, $subtotal, new AppliedDiscount($code->id(), $code->code(), $value, Money::of($amount, Currency::PLN)), null);
    }

    /**
     * The quote of a code nobody has (or a text that is no code): invalid, without discount.
     *
     * @param list<BasketLine>           $lines
     * @param array<string, BasketPrice> $prices
     *
     * @throws \InvalidArgumentException when the basket has no line, more than 50 or a variant twice
     */
    public static function withUnknownCode(array $lines, array $prices): self
    {
        $priced = self::priced($lines, $prices);

        return self::quote($priced, array_sum(array_map(static fn (array $line): int => $line['gross'], $priced)), null, DiscountCodeRejection::invalid());
    }

    /**
     * @return list<QuotedLine> the priced lines in the order of the basket
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * S: the gross of the lines before the code.
     */
    public function subtotalGross(): Money
    {
        return $this->subtotalGross;
    }

    /**
     * D: the discount of the applied code, 0 without one.
     */
    public function discountGross(): Money
    {
        return $this->discountGross;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function vat(): Money
    {
        return $this->vat;
    }

    /**
     * The gross to pay: S − D.
     */
    public function gross(): Money
    {
        return $this->gross;
    }

    public function discount(): ?AppliedDiscount
    {
        return $this->discount;
    }

    public function rejection(): ?DiscountCodeRejection
    {
        return $this->rejection;
    }

    /**
     * @param list<BasketLine>           $lines
     * @param array<string, BasketPrice> $prices
     *
     * @return list<array{line: BasketLine, price: BasketPrice, gross: int}>
     */
    private static function priced(array $lines, array $prices): array
    {
        if ([] === $lines || \count($lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException('A basket has 1 to 50 lines.');
        }
        $seen = [];
        $priced = [];
        foreach ($lines as $line) {
            $key = $line->variantId()->toString();
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('A basket has a variant in one line only.');
            }
            $seen[$key] = true;
            $price = $prices[$key] ?? null;
            if (null !== $price) {
                $priced[] = ['line' => $line, 'price' => $price, 'gross' => $price->price()->breakdown()->gross()->amount() * $line->quantity()];
            }
        }

        return $priced;
    }

    /**
     * @param list<array{line: BasketLine, price: BasketPrice, gross: int}> $priced
     */
    private static function quote(array $priced, int $subtotal, ?AppliedDiscount $discount, ?DiscountCodeRejection $rejection): self
    {
        $amount = $discount?->amount()->amount() ?? 0;
        $shares = self::allocate(array_map(static fn (array $line): int => $line['gross'], $priced), $subtotal, $amount);

        $lines = [];
        $net = 0;
        $vat = 0;
        $gross = 0;
        foreach ($priced as $index => ['line' => $line, 'price' => $price, 'gross' => $before]) {
            $share = $shares[$index];
            $amounts = PriceBreakdown::ofLineGross(Money::of($before - $share, Currency::PLN), $price->price()->breakdown()->rate());
            $lines[] = new QuotedLine($line, $price, Money::of($before, Currency::PLN), Money::of($share, Currency::PLN), $amounts);
            $net += $amounts->net()->amount();
            $vat += $amounts->vat()->amount();
            $gross += $amounts->gross()->amount();
        }

        return new self(
            $lines,
            Money::of($subtotal, Currency::PLN),
            Money::of($amount, Currency::PLN),
            Money::of($net, Currency::PLN),
            Money::of($vat, Currency::PLN),
            Money::of($gross, Currency::PLN),
            $discount,
            $rejection,
        );
    }

    /**
     * The largest-remainder allocation of the discount over the line values (see the class).
     *
     * @param list<int> $values   g_i
     * @param int       $subtotal S, their sum
     * @param int       $discount D, 0 ≤ D < S (0: nothing to allocate)
     *
     * @return list<int> d_i, in the order of the values
     */
    private static function allocate(array $values, int $subtotal, int $discount): array
    {
        if (0 === $discount) {
            return array_fill(0, \count($values), 0);
        }

        $shares = [];
        $remainders = [];
        foreach ($values as $value) {
            [$quotient, $remainder] = gmp_div_qr(gmp_mul($value, $discount), $subtotal);
            \assert($quotient instanceof \GMP && $remainder instanceof \GMP);
            // At most g_i and below S, so both are ints.
            $shares[] = gmp_intval($quotient);
            $remainders[] = gmp_intval($remainder);
        }

        $order = array_keys($values);
        usort($order, static fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        $extra = array_fill_keys(\array_slice($order, 0, $discount - array_sum($shares)), 1);

        return array_map(static fn (int $share, int $index): int => $share + ($extra[$index] ?? 0), $shares, array_keys($shares));
    }
}
