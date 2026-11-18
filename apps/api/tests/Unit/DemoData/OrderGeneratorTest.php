<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Identity\StaffRoles;
use App\DemoData\Orders\OrderGenerator;
use App\DemoData\Orders\OrderSpec;
use App\DemoData\Pricing\DiscountCodePlan;
use App\DemoData\Pricing\DiscountCodeSpec;
use App\DemoData\Profile\DemoProfile;
use App\DemoData\Profile\RunContext;
use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderNumber;
use App\Pricing\Domain\Basket\BasketLine;
use App\Pricing\Domain\Basket\BasketPrice;
use App\Pricing\Domain\Basket\BasketPromotion;
use App\Pricing\Domain\Basket\BasketQuote;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\EffectivePrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use PHPUnit\Framework\TestCase;

/**
 * The orders of the profile small as a pure function of the seed, the anchor and the index, over the catalog the earlier
 * steps generate (GeneratedOrderCatalog): numbers and times, lines priced by the rules of Pricing, statuses by age with
 * a history in order, payments, codes and the distributions of the specification.
 */
final class OrderGeneratorTest extends TestCase
{
    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';
    private const string ANCHOR = '2026-11-17T00:00:00Z';
    private const int ORDERS = 5_000;

    private static ?GeneratedOrderCatalog $catalog = null;
    /** @var list<OrderSpec>|null */
    private static ?array $orders = null;

    private static function context(): RunContext
    {
        return new RunContext(new DemoProfile('small', 20, 1_000, 2_000, self::ORDERS, 5), self::NAMESPACE, 500, new \DateTimeImmutable(self::ANCHOR));
    }

    private static function directory(): string
    {
        return \dirname(__DIR__, 3).'/fixtures/demo-data';
    }

    private static function generator(): OrderGenerator
    {
        return OrderGenerator::forRun(new Dictionaries(self::directory()), new PersonDictionaries(self::directory()), self::context());
    }

    private static function catalog(): GeneratedOrderCatalog
    {
        return self::$catalog ??= new GeneratedOrderCatalog(self::context());
    }

    /**
     * @return list<OrderSpec>
     */
    private static function orders(): array
    {
        if (null === self::$orders) {
            $generator = self::generator();
            $orders = [];
            for ($part = 0; $part < $generator->parts(); ++$part) {
                array_push($orders, ...$generator->part($part, self::catalog()));
            }
            self::$orders = $orders;
        }

        return self::$orders;
    }

    /**
     * The values of an order that come from the seed: everything but the guest token and the idempotency keys.
     *
     * @return array<string, mixed>
     */
    private static function seeded(OrderSpec $order): array
    {
        $values = get_object_vars($order);
        unset($values['accessToken'], $values['idempotencyKey']);
        $values['payment'] = null === $order->payment ? null : array_diff_key(get_object_vars($order->payment), ['idempotencyKey' => true]);

        $decoded = json_decode(json_encode($values, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testThereIsOneOrderPerIndexInPartsOfTheFixedSize(): void
    {
        $orders = self::orders();

        self::assertSame(10, self::generator()->parts());
        self::assertCount(self::ORDERS, $orders);
        foreach ($orders as $position => $order) {
            self::assertSame($position, $order->index);
            self::assertSame(OrderNumber::fromSequence(OrderNumber::FIRST + $position)->toString(), $order->number);
        }
    }

    public function testAPartIsAPureFunctionOfTheSeedExceptTheTokensAndTheKeys(): void
    {
        $first = self::generator()->part(3, self::catalog());
        $second = self::generator()->part(3, self::catalog());

        self::assertSame(array_map(self::seeded(...), $first), array_map(self::seeded(...), $second));
        foreach ($first as $position => $order) {
            $again = $second[$position];
            self::assertNotSame($order->idempotencyKey, $again->idempotencyKey);
            IdempotencyKey::fromString($order->idempotencyKey);
            if (null !== $order->accessToken) {
                self::assertNotSame($order->accessToken, $again->accessToken);
                AccessToken::fromString($order->accessToken);
            }
            if (null !== $order->payment) {
                self::assertNotSame($order->payment->idempotencyKey, $again->payment?->idempotencyKey);
            }
        }
    }

    public function testTheTimesAndTheNumbersGrowWithTheIndexInsideTheHistoryAndNoOrderIsNew(): void
    {
        $anchor = new \DateTimeImmutable(self::ANCHOR);
        $previous = null;
        foreach (self::orders() as $order) {
            self::assertNotSame('new', $order->status);
            self::assertGreaterThanOrEqual($anchor->modify('-1095 days'), $order->placedAt);
            if (null !== $previous) {
                self::assertGreaterThanOrEqual($previous, $order->placedAt);
            }
            $previous = $order->placedAt;
            self::assertEquals($order->placedAt->modify('+30 minutes'), $order->paymentDeadline);
            foreach ($order->history as $change) {
                self::assertLessThan($anchor, $change->occurredAt);
            }
        }
    }

    public function testTheLinesAreVariantsOfProductsPublishedBeforeThePlacementAtTheirEffectivePrice(): void
    {
        $catalog = self::catalog();
        foreach (self::orders() as $order) {
            self::assertGreaterThanOrEqual(1, \count($order->lines));
            self::assertLessThanOrEqual(4, \count($order->lines));
            $variants = [];
            foreach ($order->lines as $position => $line) {
                self::assertSame($position, $line->position);
                self::assertGreaterThanOrEqual(1, $line->quantity);
                self::assertLessThanOrEqual(3, $line->quantity);
                $product = $catalog->productById($line->productId);
                self::assertTrue($product->published);
                self::assertLessThan($order->placedAt, $product->firstPublishedAt);
                $variants[] = $line->variantId;

                $price = $catalog->timeline($line->variantId)->effectiveAt($order->placedAt);
                self::assertNotNull($price);
                self::assertSame($price->effective->amount(), $line->unitGross, $order->number);
                $unit = PriceBreakdown::fromGross($price->effective, $price->rate);
                self::assertSame([$unit->net()->amount(), $unit->vat()->amount(), $price->rate->basisPoints()], [$line->unitNet, $line->unitVat, $line->vatRateBp]);
                self::assertSame($price->promotion?->id, $line->promotionId);
                self::assertSame($price->promotion?->name, $line->promotionName);
                self::assertSame($price->promotion?->percentOff->value(), $line->promotionPercentOff);
                self::assertSame(null === $price->promotion ? null : $price->regular->amount(), $line->regularUnitGross);
                self::assertSame($product->taxClass, $line->taxClass);
            }
            self::assertSame(\count($variants), \count(array_unique($variants)), 'A variant is on one line of an order.');
        }
    }

    public function testTheAmountsAreTheOnesOfTheQuoteOfPricingWithTheCode(): void
    {
        $codes = [];
        foreach (new DiscountCodePlan(self::NAMESPACE, new \DateTimeImmutable(self::ANCHOR))->codes() as $code) {
            $codes[$code->code->toString()] = $code;
        }
        $rates = new PolishVatRates();
        $withCode = 0;
        foreach (self::orders() as $order) {
            $lines = [];
            $prices = [];
            foreach ($order->lines as $line) {
                $lines[] = BasketLine::of(VariantId::fromString($line->variantId), $line->quantity);
                $regular = $line->regularUnitGross ?? $line->unitGross;
                $catalogPrice = VariantPrice::reconstitute(VariantId::fromString($line->variantId), PriceBreakdown::fromGross(GrossPrice::pln($regular), $rates->rateFor(TaxClass::fromString($line->taxClass))), TaxClass::fromString($line->taxClass), 1, $order->placedAt);
                $percent = null === $line->promotionPercentOff ? null : PercentOff::of($line->promotionPercentOff);
                $effective = EffectivePrice::of($catalogPrice, $rates, $percent);
                self::assertNotNull($effective);
                $promotion = null === $line->promotionId ? null : new BasketPromotion(PromotionId::fromString($line->promotionId), (string) $line->promotionName, PercentOff::of((int) $line->promotionPercentOff));
                $prices[$line->variantId] = new BasketPrice($effective, TaxClass::fromString($line->taxClass), $promotion);
            }
            $code = null === $order->discountCode ? null : self::aggregate($codes[$order->discountCode]);
            $quote = BasketQuote::of($lines, $prices, $code, 0, $order->placedAt);

            self::assertNull($quote->rejection(), $order->number);
            self::assertSame($quote->discountGross()->amount(), $order->discountGross ?? 0);
            self::assertSame([$quote->net()->amount(), $quote->vat()->amount(), $quote->gross()->amount()], [$order->totalNet, $order->totalVat, $order->totalGross]);
            foreach ($quote->lines() as $position => $quoted) {
                $line = $order->lines[$position];
                self::assertSame(
                    [$quoted->discount()->amount(), $quoted->amounts()->net()->amount(), $quoted->amounts()->vat()->amount(), $quoted->amounts()->gross()->amount(), $quoted->unit()->gross()->amount()],
                    [$line->discountGross, $line->lineNet, $line->lineVat, $line->lineGross, $line->unitGross],
                    $order->number,
                );
            }
            if (null !== $order->discountCode) {
                ++$withCode;
                self::assertSame($codes[$order->discountCode]->id, $order->codeUsage?->codeId);
                self::assertSame($codes[$order->discountCode]->value->kind()->value, $order->discountKind);
                self::assertSame($codes[$order->discountCode]->value->value(), $order->discountValue);
                self::assertEquals($order->placedAt, $order->codeUsage->usedAt);
            } else {
                self::assertNull($order->codeUsage);
            }
        }
        self::assertGreaterThan(0, $withCode);
    }

    public function testTheHistoryFollowsTheColumnsWithTheActorsAllowedForEachTransition(): void
    {
        $accounts = new AccountGenerator(self::NAMESPACE, new PersonDictionaries(self::directory()));
        $staff = [];
        for ($number = 1; $number <= 20; ++$number) {
            $account = $accounts->staff($number);
            $staff[$account->id] = [$account->username, StaffRoles::roleOf($number)];
        }
        $allowed = ['shipped' => ['admin', 'warehouse'], 'delivered' => ['admin', 'warehouse'], 'cancelled' => ['admin', 'support'], 'returned' => ['admin', 'support']];

        foreach (self::orders() as $order) {
            $history = $order->history;
            self::assertSame([null, 'new', 'customer'], [$history[0]->from, $history[0]->to, $history[0]->actorType]);
            self::assertEquals($order->placedAt, $history[0]->occurredAt);
            self::assertSame($order->status, $history[\count($history) - 1]->to);
            foreach ($history as $position => $change) {
                self::assertSame($position, $change->position);
                if ($position > 0) {
                    self::assertSame($history[$position - 1]->to, $change->from);
                    self::assertGreaterThan($history[$position - 1]->occurredAt, $change->occurredAt);
                }
                if ('staff' === $change->actorType) {
                    self::assertArrayHasKey((string) $change->staffId, $staff);
                    self::assertSame($staff[(string) $change->staffId][0], $change->staffUsername);
                    self::assertContains($staff[(string) $change->staffId][1], $allowed[$change->to], $order->number.' '.$change->to);
                } else {
                    self::assertNull($change->staffId);
                }
            }
            self::assertSame(\count($history) + (null === $order->refundCompletedAt ? 0 : 1), $order->version);
            $this->assertColumnsMatchTheHistory($order, $allowed['returned'], $staff);
        }
    }

    /**
     * @param list<string>                         $refundRoles
     * @param array<string, array{string, string}> $staff
     */
    private function assertColumnsMatchTheHistory(OrderSpec $order, array $refundRoles, array $staff): void
    {
        $at = [];
        foreach ($order->history as $change) {
            $at[$change->to] = $change;
        }
        $paid = $at['paid'] ?? null;
        self::assertEquals($paid?->occurredAt, $order->paidAt);
        self::assertSame('system', $paid->actorType ?? 'system');
        self::assertEquals($at['shipped']->occurredAt ?? null, $order->shippedAt);
        self::assertEquals($at['delivered']->occurredAt ?? null, $order->deliveredAt);
        self::assertEquals($at['returned']->occurredAt ?? null, $order->returnedAt);
        self::assertEquals($at['cancelled']->occurredAt ?? null, $order->cancelledAt);

        if ('cancelled' === $order->status) {
            $byStaff = null !== $order->paidAt;
            self::assertSame($byStaff ? 'staff' : 'payment_timeout', $order->cancellationReason);
            self::assertSame($byStaff ? 'staff' : 'system', $at['cancelled']->actorType);
            if (!$byStaff) {
                self::assertEquals($order->placedAt->modify('+35 minutes'), $order->cancelledAt);
                if (null !== $order->codeUsage) {
                    self::assertEquals($order->cancelledAt, $order->codeUsage->releasedAt);
                }
            }
        } else {
            self::assertNull($order->cancellationReason);
            self::assertNull($order->codeUsage?->releasedAt);
        }

        self::assertSame(null !== $order->paidAt, null !== $order->payment);
        if (null !== $order->payment) {
            self::assertSame($order->totalGross, $order->payment->amount);
            self::assertEquals($order->paidAt, $order->payment->executedAt);
            self::assertSame($order->paymentId, $order->payment->id);
            self::assertContains($order->payment->provider, ['demo-transfer', 'demo-card']);
            self::assertGreaterThanOrEqual($order->placedAt, $order->payment->createdAt);
            self::assertLessThanOrEqual($order->paidAt, $order->payment->createdAt);
        }

        $refunded = 'returned' === $order->status || 'staff' === $order->cancellationReason;
        self::assertSame($refunded ? $order->totalGross : null, $order->refundAmount);
        $from = $order->returnedAt ?? $order->cancelledAt;
        self::assertEquals($refunded ? $from?->modify(Order::REFUND_TIME) : null, $order->refundDueAt);
        if (null !== $order->refundCompletedAt) {
            self::assertTrue($refunded);
            self::assertGreaterThanOrEqual($order->refundDueAt, $order->refundCompletedAt);
            self::assertLessThan(new \DateTimeImmutable(self::ANCHOR), $order->refundCompletedAt);
            self::assertContains($staff[(string) $order->refundCompletedById][1], $refundRoles);
            self::assertSame($staff[(string) $order->refundCompletedById][0], $order->refundCompletedByUsername);
        } elseif (null !== $order->refundDueAt) {
            self::assertGreaterThan(new \DateTimeImmutable(self::ANCHOR), $order->refundDueAt->modify('+2 days'), 'A refund older than 14 days is settled.');
        }
    }

    public function testTheCustomersAreGeneratedAccountsWithTheirDataAndTheGuestsHaveAnAddressOfTheirOrder(): void
    {
        $accounts = new AccountGenerator(self::NAMESPACE, new PersonDictionaries(self::directory()));
        $ids = [];
        for ($number = 1; $number <= 1_000; ++$number) {
            $ids[$accounts->customerId($number)] = $number;
        }
        $perCustomer = [];
        foreach (self::orders() as $order) {
            self::assertMatchesRegularExpression('/@example\.(com|net|org)$/D', $order->email);
            if ('guest' === $order->ownerType) {
                self::assertNull($order->customerId);
                self::assertNotNull($order->accessToken);
                $person = $accounts->person('guest', OrderNumber::FIRST + $order->index);
                self::assertSame($person->email('g'.(OrderNumber::FIRST + $order->index)), $order->email);
                continue;
            }
            self::assertNull($order->accessToken);
            self::assertArrayHasKey((string) $order->customerId, $ids);
            $account = $accounts->customer($ids[(string) $order->customerId]);
            self::assertSame([$account->email, $account->person->fullName(), $account->person->phone, $account->person->street, $account->person->postalCode, $account->person->city], [$order->email, $order->fullName, $order->phone, $order->street, $order->postalCode, $order->city]);
            $perCustomer[(string) $order->customerId] = ($perCustomer[(string) $order->customerId] ?? 0) + 1;
        }

        // A long tail: most customers with one to three orders, a few with more than fifty, some with none.
        $counts = array_values($perCustomer);
        self::assertNotSame([], $counts);
        self::assertLessThan(1_000, \count($counts), 'Some customers have no order.');
        self::assertGreaterThan(50, max($counts));
        self::assertLessThan(10, \count(array_filter($counts, static fn (int $count): bool => $count > 50)));
        self::assertGreaterThan(0.5, \count(array_filter($counts, static fn (int $count): bool => $count <= 3)) / \count($counts));
    }

    public function testTheDistributionsAreTheOnesOfTheSpecification(): void
    {
        $anchor = new \DateTimeImmutable(self::ANCHOR);
        $orders = self::orders();
        $total = \count($orders);
        $count = static fn (callable $filter, array $of): int => \count(array_filter($of, $filter));

        self::assertEqualsWithDelta(0.40, $count(static fn (OrderSpec $order): bool => 'guest' === $order->ownerType, $orders) / $total, 0.03);
        self::assertEqualsWithDelta(0.10, $count(static fn (OrderSpec $order): bool => null !== $order->discountCode, $orders) / $total, 0.04);
        self::assertEqualsWithDelta(0.06, $count(static fn (OrderSpec $order): bool => 'cancelled' === $order->status, $orders) / $total, 0.02);

        $old = array_filter($orders, static fn (OrderSpec $order): bool => $order->placedAt < $anchor->modify('-30 days'));
        self::assertGreaterThan(0.85, $count(static fn (OrderSpec $order): bool => 'delivered' === $order->status, $old) / \count($old));
        self::assertSame(0, $count(static fn (OrderSpec $order): bool => \in_array($order->status, ['paid', 'shipped'], true), $old));
        self::assertEqualsWithDelta(0.04, $count(static fn (OrderSpec $order): bool => 'returned' === $order->status, $old) / \count($old), 0.015);

        $recent = array_filter($orders, static fn (OrderSpec $order): bool => $order->placedAt >= $anchor->modify('-2 days'));
        $statuses = array_count_values(array_map(static fn (OrderSpec $order): string => $order->status, $recent));
        self::assertArrayHasKey('paid', $statuses);
        self::assertArrayHasKey('shipped', $statuses);
        self::assertArrayNotHasKey('returned', $statuses);
    }

    private static function aggregate(DiscountCodeSpec $code): DiscountCode
    {
        return DiscountCode::reconstitute(DiscountCodeId::fromString($code->id), $code->code, $code->value, $code->minimumOrder, $code->period, $code->usageLimit, $code->enabled, $code->createdAt, null, $code->createdAt, null, 1);
    }
}
