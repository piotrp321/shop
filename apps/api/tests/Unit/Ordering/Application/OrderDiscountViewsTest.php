<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Application\View\CustomerOrderView;
use App\Ordering\Application\View\OrderDiscountView;
use App\Ordering\Application\View\OrderLinePromotionView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\Money;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDiscount;
use App\Ordering\Domain\OrderDiscountKind;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\PromotionCopy;
use App\Ordering\Domain\VariantId;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\TestCase;

/**
 * The views of an order with a discount code and a promotion (Q5): every view has the values of the lines before and
 * after the discount and the copy of the code; only the panel's has the promotion of a line.
 */
final class OrderDiscountViewsTest extends TestCase
{
    private const string PROMOTION = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6e00';

    public function testTheViewOfThePlacementHasTheDiscountAndTheValuesOfTheLines(): void
    {
        $view = OrderView::of(self::order());

        self::assertEquals(new OrderDiscountView('MINUS50', 'amount', 5_000, 5_000), $view->discount);
        self::assertSame([24_600, 4_545, 20_055], [$view->lines[0]->grossBeforeDiscount, $view->lines[0]->discountGross, $view->lines[0]->lineGross]);
        self::assertSame([12_300, 455, 11_845], [$view->lines[1]->grossBeforeDiscount, $view->lines[1]->discountGross, $view->lines[1]->lineGross]);
        self::assertSame(31_900, $view->totals->gross, 'the total after the discount');
    }

    public function testTheViewOfTheCustomerHasTheDiscount(): void
    {
        $view = CustomerOrderView::of(self::order(), new \DateTimeImmutable(OrderExamples::PLACED_AT));

        self::assertEquals(new OrderDiscountView('MINUS50', 'amount', 5_000, 5_000), $view->discount);
        self::assertSame(4_545, $view->lines[0]->discountGross);
    }

    public function testThePanelSeesThePromotionOfALine(): void
    {
        $view = BackofficeOrderView::of(self::order(), new \DateTimeImmutable(OrderExamples::PLACED_AT));

        self::assertEquals(new OrderDiscountView('MINUS50', 'amount', 5_000, 5_000), $view->discount);
        self::assertEquals(new OrderLinePromotionView('Jesień -20%', 20, 15_375), $view->lines[0]->promotion);
        self::assertNull($view->lines[1]->promotion);
    }

    public function testAnOrderWithoutACodeHasNoDiscountAndEqualValuesBeforeAndAfter(): void
    {
        $view = OrderView::of(OrderExamples::placed());

        self::assertNull($view->discount);
        foreach ($view->lines as $line) {
            self::assertSame(0, $line->discountGross);
            self::assertSame($line->grossBeforeDiscount, $line->lineGross);
        }
    }

    private static function order(): Order
    {
        $example = OrderExamples::placed();
        $product = ProductId::fromString('c0ffee00-7c1a-7c4e-9a6e-000000000042');
        $price = PriceCopy::of(10_000, 2_300, 12_300, 2300, 'standard', Currency::PLN);

        return Order::place(
            $example->id(),
            $example->number(),
            $example->owner(),
            $example->accessToken(),
            $example->contact(),
            $example->shippingAddress(),
            [
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000001'), $product, 'Buty Asfalt', '42', 2, $price, LineAmounts::of(4_545, 16_305, 3_750, 20_055, Currency::PLN), PromotionCopy::of(self::PROMOTION, 'Jesień -20%', 20, 15_375)),
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000002'), $product, 'Czapka', '', 1, $price, LineAmounts::of(455, 9_630, 2_215, 11_845, Currency::PLN), null),
            ],
            OrderDiscount::of('MINUS50', OrderDiscountKind::Amount, 5_000, Money::of(5_000, Currency::PLN)),
            $example->idempotencyKey(),
            $example->requestFingerprint(),
            $example->placedAt(),
        );
    }
}
