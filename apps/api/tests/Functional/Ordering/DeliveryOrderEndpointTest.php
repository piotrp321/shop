<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\OrderAccess;
use App\Ordering\Application\Query\GetOrderForPayment;
use App\Ordering\Application\Query\OrderForPayment;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\InputConformance\InputValues;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/orders and /api/v1/me/orders with a delivery method (docs/features/delivery/spec.md, W5, W6, P6, P15):
 * the order keeps the copy of the method, its cost split over the VAT rates of the goods and the total with it; an
 * unknown or inactive method is a 409 that changes nothing; a changed price or a code that takes the goods below the
 * threshold is a changed total; the refusals come in the order of W5; the method is part of the fingerprint. The views
 * of the order (placement, history of the customer, panel) carry the delivery, null for an order without a method.
 */
final class DeliveryOrderEndpointTest extends WebTestCase
{
    use Factories;
    use OrderEndpointTestTrait;

    private const string PATH = '/api/v1/orders';
    private const string CUSTOMER_PATH = '/api/v1/me/orders';
    private const string EMAIL = 'anna.nowak@example.com';
    private const string TEMPLATE = 'https://tracking.example.com/{numer}';
    private const int COST = 1_999;
    /** 2 × 123,00 zł: 200,00 zł net and 46,00 zł VAT at 23 %. */
    private const int GOODS = 24_600;
    private const string UNAVAILABLE = 'Wybrany sposób dostawy jest niedostępny. Wybierz inny.';
    private const string TOTAL_CHANGED = 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.';

    public function testAGuestOrderKeepsTheCopyOfTheMethodItsCostSplitAtTheRateOfTheGoodsAndTheTotalWithIt(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST, 30_000, trackingUrlTemplate: self::TEMPLATE, name: 'Kurier standardowy');
        [$shoes, $cart] = $this->cart();

        $order = $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $method));

        self::assertResponseStatusCodeSame(201);
        $this->assertOrderView($order);
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        self::assertSame(['methodName' => 'Kurier standardowy', 'gross' => self::COST], $order['delivery']);
        // 19,99 zł at 23 %: net 16,25 zł half up, VAT 3,74 zł.
        self::assertSame(['net' => 20_000 + 1_625, 'vat' => 4_600 + 374, 'gross' => self::GOODS + self::COST, 'currency' => 'PLN'], $order['totals']);
        self::assertIsString($order['orderId']);
        self::assertSame(
            [$method, 'Kurier standardowy', self::TEMPLATE, self::COST, 1_625, 374, self::GOODS + self::COST],
            $this->connection->fetchNumeric('SELECT delivery_method_id, delivery_method_name, delivery_tracking_url_template, delivery_gross, delivery_net, delivery_vat, total_gross FROM ordering_order WHERE id = ?', [$order['orderId']]),
        );
        self::assertSame([[2300, self::COST, 1_625, 374]], $this->connection->fetchAllNumeric('SELECT vat_rate_bp, gross, net, vat FROM ordering_order_delivery_vat WHERE order_id = ?', [$order['orderId']]));
        self::assertSame([$shoes => 2], OrderingFixture::reservation($this->connection, $order['orderId']));
        self::assertIsString($order['accessToken']);
        $forPayment = $this->queryBus()->dispatch(new GetOrderForPayment($order['orderId'], OrderAccess::guest($order['accessToken'])))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(OrderForPayment::class, $forPayment);
        self::assertSame(self::GOODS + self::COST, $forPayment->totalGross, 'the payment is for the total with the delivery');
    }

    public function testACustomerOrderHasTheDeliveryAlsoInTheDetailOfTheHistoryAndInThePanel(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST, name: 'Kurier ekspresowy');
        $shoes = $this->variant(12_300, 10);
        CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 2]]);

        $order = $this->place(self::CUSTOMER_PATH, [...self::details(self::GOODS + self::COST), 'deliveryMethodId' => $method], token: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(201);
        $this->assertOrderView($order);
        self::assertSame(['methodName' => 'Kurier ekspresowy', 'gross' => self::COST], $order['delivery']);
        self::assertIsString($order['orderId']);

        $detail = $this->request('GET', self::CUSTOMER_PATH.'/'.$order['orderId'], token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(200);
        self::assertMatchesSchema('CustomerOrder', $detail);
        self::assertSame(['methodName' => 'Kurier ekspresowy', 'gross' => self::COST], $detail['delivery']);
        self::assertSame(['net' => 20_000 + 1_625, 'vat' => 4_600 + 374, 'gross' => self::GOODS + self::COST, 'currency' => 'PLN'], $detail['totals']);

        $panel = $this->request('GET', '/api/v1/admin/orders/'.$order['orderId'], token: TestTokenFactory::staff(['admin']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['methodName' => 'Kurier ekspresowy', 'gross' => self::COST], $panel['delivery']);
        self::assertIsArray($panel['totals']);
        self::assertSame(self::GOODS + self::COST, $panel['totals']['gross']);
    }

    public function testAnOrderWithoutAMethodHasNoDeliveryInTheHistoryOfTheCustomerAndInThePanel(): void
    {
        $orderId = OrderingFixture::order($this->connection, customerId: TestTokenFactory::CUSTOMER_ID);

        $detail = $this->request('GET', self::CUSTOMER_PATH.'/'.$orderId, token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(200);
        self::assertMatchesSchema('CustomerOrder', $detail);
        self::assertArrayHasKey('delivery', $detail);
        self::assertNull($detail['delivery']);

        $panel = $this->request('GET', '/api/v1/admin/orders/'.$orderId, token: TestTokenFactory::staff(['admin']));
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('delivery', $panel);
        self::assertNull($panel['delivery']);
    }

    public function testFromTheThresholdTheDeliveryIsFreeWithItsMethodAndWithoutShares(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST, self::GOODS, name: 'Kurier standardowy');
        [, $cart] = $this->cart();

        $order = $this->place(self::PATH, $this->body($cart, self::GOODS, $method));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['methodName' => 'Kurier standardowy', 'gross' => 0], $order['delivery']);
        self::assertSame(['net' => 20_000, 'vat' => 4_600, 'gross' => self::GOODS, 'currency' => 'PLN'], $order['totals']);
        self::assertIsString($order['orderId']);
        self::assertSame([$method, 0], $this->connection->fetchNumeric('SELECT delivery_method_id, delivery_gross FROM ordering_order WHERE id = ?', [$order['orderId']]));
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order_delivery_vat WHERE order_id = ?', [$order['orderId']]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unavailableMethods(): iterable
    {
        yield 'inactive' => ['inactive'];
        yield 'unknown' => [Uuid::v7()->toRfc4122()];
        foreach (InputValues::uuidsOutsideTheVersions() as $name => [$uuid]) {
            yield $name => [$uuid];
        }
    }

    #[DataProvider('unavailableMethods')]
    public function testAnInactiveOrUnknownMethodIsAConflictWithoutOrderReservationUsageOfTheCodeOrChangeOfTheCart(string $method): void
    {
        if ('inactive' === $method) {
            $method = OrderingFixture::deliveryMethod($this->connection, self::COST, active: false);
        }
        $code = $this->code('DOSTAWA-D1');
        [$shoes, $cart] = $this->cart();

        $problem = $this->place(self::PATH, [...$this->body($cart, self::GOODS - 2_460 + self::COST, $method), 'discountCode' => 'DOSTAWA-D1']);

        $this->assertConflict('delivery-method-unavailable', self::UNAVAILABLE, $problem);
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage WHERE code_id = ?', [$code->id()->toString()]));
        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
    }

    public function testAnInactiveMethodIsAConflictForACustomerToo(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST, active: false);
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 2]]);

        $problem = $this->place(self::CUSTOMER_PATH, [...self::details(self::GOODS + self::COST), 'deliveryMethodId' => $method], token: TestTokenFactory::customer());

        $this->assertConflict('delivery-method-unavailable', self::UNAVAILABLE, $problem);
        self::assertSame(0, $this->orders());
        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
    }

    public function testAChangedPriceOfTheMethodIsAChangedTotal(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST);
        [$shoes, $cart] = $this->cart();
        $this->connection->executeStatement('UPDATE delivery_method SET price_gross = 2499 WHERE id = ?', [$method]);

        $this->assertConflict('order-total-changed', self::TOTAL_CHANGED, $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $method)));

        self::assertSame(0, $this->orders());
        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
        $order = $this->place(self::PATH, $this->body($cart, self::GOODS + 2_499, $method), key: 'key-new-price');
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2_499, \is_array($order['delivery']) ? $order['delivery']['gross'] : null);
    }

    /**
     * Decision P6: the threshold is compared with the goods after the code, so a code can take the free delivery away.
     */
    public function testACodeThatTakesTheGoodsBelowTheThresholdMakesTheDeliveryPaid(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST, self::GOODS);
        $this->code('DOSTAWA-D2');
        [, $cart] = $this->cart();
        // 10 % of 246,00 zł: the goods are 221,40 zł, below the threshold of 246,00 zł.
        $afterCode = self::GOODS - 2_460;

        $this->assertConflict('order-total-changed', self::TOTAL_CHANGED, $this->place(self::PATH, [...$this->body($cart, $afterCode, $method), 'discountCode' => 'DOSTAWA-D2']));
        self::assertSame(0, $this->orders());

        $order = $this->place(self::PATH, [...$this->body($cart, $afterCode + self::COST, $method), 'discountCode' => 'DOSTAWA-D2'], key: 'key-paid-delivery');

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($order['delivery']);
        self::assertSame(self::COST, $order['delivery']['gross']);
        self::assertIsArray($order['totals']);
        self::assertSame($afterCode + self::COST, $order['totals']['gross']);
    }

    public function testARejectedCodeIsRefusedBeforeAnUnavailableMethod(): void
    {
        [, $cart] = $this->cart();

        $problem = $this->place(self::PATH, [...$this->body($cart, self::GOODS, Uuid::v7()->toRfc4122()), 'discountCode' => 'NIE-MA-TAKIEGO']);

        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-invalid', $problem['type'] ?? null);
        self::assertSame(0, $this->orders());
    }

    public function testAnUnavailableMethodIsRefusedBeforeAChangedTotal(): void
    {
        [, $cart] = $this->cart();

        $this->assertConflict('delivery-method-unavailable', self::UNAVAILABLE, $this->place(self::PATH, $this->body($cart, 1, Uuid::v7()->toRfc4122())));
    }

    public function testAChangedTotalIsRefusedBeforeAShortage(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST);
        $last = $this->variant(12_300, 1);
        $cart = CartFixture::cart($this->connection, lines: [[$last, 2]]);

        $this->assertConflict('order-total-changed', self::TOTAL_CHANGED, $this->place(self::PATH, $this->body($cart, self::GOODS, $method)));
        $this->assertConflict('insufficient-stock-for-order', 'Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $method), key: 'key-shortage'));
    }

    public function testARepeatWithTheSameKeyAndMethodGivesTheSameOrderAlsoInAnotherLetterCaseAndAnotherMethodIsAnotherRequest(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST);
        $other = OrderingFixture::deliveryMethod($this->connection, self::COST);
        [, $cart] = $this->cart();
        $first = $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $method));
        self::assertResponseStatusCodeSame(201);

        foreach (InputValues::equivalentUuidForms($method) as [$form]) {
            self::assertSame($first, $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $form)));
            self::assertResponseStatusCodeSame(200);
        }
        $this->assertKeyReused($this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $other)), $first);
        self::assertSame(1, $this->orders());
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order_delivery_vat'));
    }

    public function testARepeatGivesTheOrderEvenAfterItsMethodWasDisabled(): void
    {
        $method = OrderingFixture::deliveryMethod($this->connection, self::COST);
        [, $cart] = $this->cart();
        $first = $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $method));
        $this->connection->executeStatement('UPDATE delivery_method SET active = false WHERE id = ?', [$method]);

        self::assertSame($first, $this->place(self::PATH, $this->body($cart, self::GOODS + self::COST, $method)));
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * A guest cart of 2 × 123,00 zł (10 in stock).
     *
     * @return array{string, string} the variant and the cart
     */
    private function cart(): array
    {
        $shoes = $this->variant(12_300, 10);

        return [$shoes, CartFixture::cart($this->connection, lines: [[$shoes, 2]])];
    }

    /**
     * A code of 10 % without a minimum.
     */
    private function code(string $code): DiscountCode
    {
        $created = DiscountCodeFactory::createOne(['code' => $code, 'value' => 10]);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($created);

        return $created;
    }

    private function queryBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $cartId, int $expectedTotalGross, string $deliveryMethodId): array
    {
        return ['cartId' => $cartId, 'email' => self::EMAIL, ...self::details($expectedTotalGross), 'deliveryMethodId' => $deliveryMethodId];
    }
}
