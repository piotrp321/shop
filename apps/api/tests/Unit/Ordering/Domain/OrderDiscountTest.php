<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\DiscountCodeRejected;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\Money;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDiscount;
use App\Ordering\Domain\OrderDiscountKind;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\PromotionCopy;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;
use App\Shared\Domain\Exception\Conflict;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\TestCase;

/**
 * The values of a line from the quote, the copy of the promotion and of the discount code, and the rejection of a
 * code (DISCOUNT-CODES, "Ordering").
 */
final class OrderDiscountTest extends TestCase
{
    private const string PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-000000000042';
    private const string PROMOTION = '0192f0c4-7c1a-7c4e-9a6e-00000000f001';

    public function testALineKeepsTheValuesOfTheQuoteAfterItsDiscount(): void
    {
        // 3 × 123,00 zł = 369,00 zł, 36,90 zł of the code; net and VAT of 332,10 zł at 23 %.
        $line = self::line(1, 3, self::price(10_000, 2_300), LineAmounts::of(3_690, 27_000, 6_210, 33_210, Currency::PLN));

        self::assertSame(36_900, $line->grossBeforeDiscount()->amount());
        self::assertSame(3_690, $line->discount()->amount());
        self::assertSame(33_210, $line->lineGross()->amount());
        self::assertSame(27_000, $line->lineNet()->amount());
        self::assertSame(6_210, $line->lineVat()->amount());
        self::assertNull($line->promotion());
    }

    public function testTheNetOfALineWithoutADiscountIsTheOneOfTheQuoteNotTheUnitNetTimesTheQuantity(): void
    {
        // 3 × 1,23 zł: the unit net times 3 is 3,00 zł, the net of 3,69 zł gross at 23 % is 3,00 zł too, but the
        // quote may round otherwise (decision D8); the line keeps what it was given.
        $line = self::line(1, 3, self::price(100, 23), LineAmounts::of(0, 301, 68, 369, Currency::PLN));

        self::assertSame([301, 68, 369, 0], [$line->lineNet()->amount(), $line->lineVat()->amount(), $line->lineGross()->amount(), $line->discount()->amount()]);
    }

    public function testLineAmountsHaveNetPlusVatEqualToGross(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LineAmounts::of(0, 300, 68, 369, Currency::PLN);
    }

    public function testALineWhoseGrossIsNotTheGrossBeforeDiscountLessTheDiscountIsRefused(): void
    {
        foreach ([[0, 300, 68, 368], [1, 300, 69, 369], [370, 0, 0, 0]] as [$discount, $net, $vat, $gross]) {
            try {
                self::line(1, 3, self::price(100, 23), LineAmounts::of($discount, $net, $vat, $gross, Currency::PLN));
                self::fail(\sprintf('Expected InvalidOrder for a discount of %d and a gross of %d.', $discount, $gross));
            } catch (InvalidOrder) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testALineDiscountedToZeroIsAllowed(): void
    {
        $line = self::line(1, 1, self::price(1, 0), LineAmounts::of(1, 0, 0, 0, Currency::PLN));

        self::assertSame(0, $line->lineGross()->amount());
    }

    public function testALineKeepsTheCopyOfItsPromotion(): void
    {
        $promotion = PromotionCopy::of(strtoupper(self::PROMOTION), 'Jesień -20%', 20, 15_375);
        $line = self::line(1, 1, self::price(10_000, 2_300), LineAmounts::of(0, 10_000, 2_300, 12_300, Currency::PLN), $promotion);

        self::assertSame($promotion, $line->promotion());
        self::assertSame([self::PROMOTION, 'Jesień -20%', 20, 15_375], [$promotion->promotionId(), $promotion->name(), $promotion->percentOff(), $promotion->regularUnitGross()->amount()]);
    }

    public function testAPromotionCopyKeepsTheRulesOfItsColumns(): void
    {
        foreach ([['not-a-uuid', 'Jesień', 20, 15_375], [self::PROMOTION, '', 20, 15_375], [self::PROMOTION, str_repeat('n', 101), 20, 15_375], [self::PROMOTION, 'Jesień', 0, 15_375], [self::PROMOTION, 'Jesień', 100, 15_375], [self::PROMOTION, 'Jesień', 20, 0]] as [$id, $name, $percent, $regular]) {
            try {
                PromotionCopy::of($id, $name, $percent, $regular);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(str_repeat('n', 100), PromotionCopy::of(self::PROMOTION, str_repeat('n', 100), 99, 1)->name());
    }

    public function testAnOrderWithACodeHasTheSumOfTheLineDiscountsAsItsDiscount(): void
    {
        $discount = OrderDiscount::of('WITAJ10', OrderDiscountKind::Percent, 10, Money::of(3_890, Currency::PLN));

        $order = self::order([
            self::line(1, 3, self::price(10_000, 2_300), LineAmounts::of(3_690, 27_000, 6_210, 33_210, Currency::PLN)),
            self::line(2, 1, self::price(1_626, 374), LineAmounts::of(200, 1_463, 337, 1_800, Currency::PLN)),
        ], $discount);

        self::assertSame($discount, $order->discount());
        self::assertSame([28_463, 6_547, 35_010], [$order->totals()->net()->amount(), $order->totals()->vat()->amount(), $order->totals()->gross()->amount()], 'the totals are after the discount');
    }

    public function testAnOrderWhoseLineDiscountsDoNotAddUpToItsDiscountIsRefused(): void
    {
        $lines = [self::line(1, 3, self::price(10_000, 2_300), LineAmounts::of(3_690, 27_000, 6_210, 33_210, Currency::PLN))];

        foreach ([OrderDiscount::of('WITAJ10', OrderDiscountKind::Percent, 10, Money::of(3_691, Currency::PLN)), null] as $discount) {
            try {
                self::order($lines, $discount);
                self::fail('Expected InvalidOrder.');
            } catch (InvalidOrder) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnOrderWithACodeOfAnAmountWithoutALineDiscountIsRefused(): void
    {
        $this->expectException(InvalidOrder::class);

        self::order([self::line(1, 1, self::price(10_000, 2_300), LineAmounts::of(0, 10_000, 2_300, 12_300, Currency::PLN))], OrderDiscount::of('MINUS50', OrderDiscountKind::Amount, 5_000, Money::of(5_000, Currency::PLN)));
    }

    public function testADiscountCopyKeepsTheRulesOfItsColumns(): void
    {
        foreach ([['', 10, 1], [str_repeat('A', 33), 10, 1], ['WITAJ10', 0, 1], ['WITAJ10', 10, 0]] as [$code, $value, $amount]) {
            try {
                OrderDiscount::of($code, OrderDiscountKind::Percent, $value, Money::of($amount, Currency::PLN));
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $copy = OrderDiscount::of('MINUS50', OrderDiscountKind::Amount, 5_000, Money::of(5_000, Currency::PLN));
        self::assertSame(['MINUS50', OrderDiscountKind::Amount, 5_000, 5_000], [$copy->code(), $copy->kind(), $copy->value(), $copy->amount()->amount()]);
    }

    public function testReconstituteKeepsTheStoredValuesOfTheLinesAndTheDiscount(): void
    {
        $discount = OrderDiscount::of('WITAJ10', OrderDiscountKind::Percent, 10, Money::of(3_690, Currency::PLN));
        $promotion = PromotionCopy::of(self::PROMOTION, 'Jesień -20%', 20, 15_375);
        $order = self::order([self::line(1, 3, self::price(10_000, 2_300), LineAmounts::of(3_690, 27_000, 6_210, 33_210, Currency::PLN), $promotion)], $discount);

        $copy = OrderExamples::copy($order);

        self::assertEquals($order->discount(), $copy->discount());
        self::assertEquals($order->lines(), $copy->lines());
        self::assertSame(33_210, $copy->totals()->gross()->amount());
        self::assertSame(3_690, $copy->lines()[0]->discount()->amount());
    }

    public function testTheRejectionOfACodeIsAConflictWithTheTypeAndTheDetailItIsGiven(): void
    {
        $rejected = DiscountCodeRejected::of('tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum', 'Ten kod działa przy zamówieniu od 300,00 zł.', 30_000);

        self::assertContains(Conflict::class, class_implements($rejected), 'a 409');
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum', $rejected->problemType());
        self::assertSame('Ten kod działa przy zamówieniu od 300,00 zł.', $rejected->getMessage());
        self::assertSame(['minimumOrderGross' => 30_000], $rejected->problemExtensions());

        $invalid = DiscountCodeRejected::of('tag:piotrp321.github.io,2026:shop/problems/discount-code-invalid', 'Ten kod jest nieprawidłowy albo nieaktywny.', null);
        self::assertSame([], $invalid->problemExtensions(), 'no minimum unless below it');
    }

    public function testTheFingerprintWithoutACodeIsTheOneOfARequestBeforeDiscountCodes(): void
    {
        $fingerprint = RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), self::address(), 75_800);

        // The hash of the same request before discount codes, so a retry with a key of that time is still a replay.
        self::assertSame('45d8891143adea4162fe12830184a04efb4efd464e110a5e175b9a4f7d60027f', $fingerprint->toString());
        self::assertTrue($fingerprint->equals(RequestFingerprint::of('guest:6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b', self::contact(), self::address(), 75_800, null)));
    }

    public function testTheCodeIsPartOfTheFingerprint(): void
    {
        $without = RequestFingerprint::of('guest:cart', self::contact(), self::address(), 75_800);
        $with = RequestFingerprint::of('guest:cart', self::contact(), self::address(), 75_800, 'WITAJ10');

        self::assertFalse($with->equals($without));
        self::assertFalse($with->equals(RequestFingerprint::of('guest:cart', self::contact(), self::address(), 75_800, 'MINUS50')));
        self::assertTrue($with->equals(RequestFingerprint::of('guest:cart', self::contact(), self::address(), 75_800, 'WITAJ10')));
    }

    /**
     * @param list<OrderLine> $lines
     */
    private static function order(array $lines, ?OrderDiscount $discount): Order
    {
        $order = OrderExamples::placed();

        return Order::place($order->id(), $order->number(), $order->owner(), $order->accessToken(), $order->contact(), $order->shippingAddress(), $lines, $discount, $order->idempotencyKey(), $order->requestFingerprint(), $order->placedAt());
    }

    private static function line(int $variant, int $quantity, PriceCopy $price, LineAmounts $amounts, ?PromotionCopy $promotion = null): OrderLine
    {
        return OrderLine::of(VariantId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $variant)), ProductId::fromString(self::PRODUCT), 'Buty Asfalt', 'czarne, 42', $quantity, $price, $amounts, $promotion);
    }

    private static function price(int $net, int $vat): PriceCopy
    {
        return PriceCopy::of($net, $vat, $net + $vat, 2300, 'standard', Currency::PLN);
    }

    private static function contact(): Contact
    {
        return Contact::of(EmailAddress::fromString('jan@example.com'), 'Jan Kowalski', '600100200');
    }

    private static function address(): ShippingAddress
    {
        return ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');
    }
}
