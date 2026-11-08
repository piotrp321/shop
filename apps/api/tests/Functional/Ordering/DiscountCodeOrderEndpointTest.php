<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\OrderAccess;
use App\Ordering\Application\Query\GetOrderForPayment;
use App\Ordering\Application\Query\OrderForPayment;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/orders and /api/v1/me/orders with a discount code (DISCOUNT-CODES, "Ordering", "API"): the order with
 * the copy of the code and the discount of its lines, the usage of the code, each 409 of a rejected code, a blank code
 * as none, a replay without a second usage, the payment amount after the discount, the release of the usage on expiry
 * and the order views of the customer and the panel.
 */
final class DiscountCodeOrderEndpointTest extends WebTestCase
{
    use Factories;
    use OrderEndpointTestTrait;

    private const string PATH = '/api/v1/orders';
    private const string CUSTOMER_PATH = '/api/v1/me/orders';
    private const string EMAIL = 'anna.nowak@example.com';

    public function testAGuestPlacesAnOrderWithACodeAndItsUsageIsRecorded(): void
    {
        $code = $this->code(['code' => 'WITAJ-F1', 'value' => 10]);
        [$shoes, $map, $cart] = $this->cart();

        // 2 × 123,00 + 1 × 12,30 = 258,30 zł; 10 % is 25,83 zł.
        $order = $this->place(self::PATH, $this->body($cart, 25_830 - 2_583, ' witaj-f1 '));

        self::assertResponseStatusCodeSame(201);
        $this->assertOrderView($order);
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        self::assertSame(['code' => 'WITAJ-F1', 'kind' => 'percent', 'value' => 10, 'amountGross' => 2_583], $order['discount']);
        [$first, $second] = [self::line($order, 0), self::line($order, 1)];
        self::assertSame([$shoes, 24_600], [$first['variantId'], $first['grossBeforeDiscount']]);
        self::assertSame([$map, 1_230], [$second['variantId'], $second['grossBeforeDiscount']]);
        self::assertSame(2_583, self::int($first['discountGross']) + self::int($second['discountGross']), 'the discount is allocated over the lines');
        foreach ([$first, $second] as $line) {
            self::assertSame(self::int($line['grossBeforeDiscount']) - self::int($line['discountGross']), $line['lineGross']);
        }
        self::assertIsArray($order['totals']);
        self::assertSame(23_247, $order['totals']['gross']);
        self::assertSame(23_247, self::int($order['totals']['net']) + self::int($order['totals']['vat']));
        self::assertSame(1, $this->activeUses($code));
        self::assertIsString($order['orderId']);
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage WHERE order_id = ? AND code_id = ?', [$order['orderId'], $code->id()->toString()]));
        self::assertSame(['WITAJ-F1', 'percent', 10, 2_583], $this->connection->fetchNumeric('SELECT discount_code, discount_kind, discount_value, discount_gross FROM ordering_order WHERE id = ?', [$order['orderId']]));
    }

    public function testThePaymentIsForTheTotalAfterTheDiscount(): void
    {
        $this->code(['code' => 'MINUS-F2', 'kind' => DiscountKind::Amount, 'value' => 5_000, 'minimumOrderGross' => 20_000]);
        [, , $cart] = $this->cart();

        $order = $this->place(self::PATH, $this->body($cart, 25_830 - 5_000, 'MINUS-F2'));

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($order['orderId']);
        self::assertIsString($order['accessToken']);
        $forPayment = $this->queryBus()->dispatch(new GetOrderForPayment($order['orderId'], OrderAccess::guest($order['accessToken'])))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(OrderForPayment::class, $forPayment);
        self::assertSame(20_830, $forPayment->totalGross);
    }

    public function testAnUnknownDisabledOrExpiredCodeIsInvalidAndNothingIsPlaced(): void
    {
        $this->code(['code' => 'OFF-F3', 'enabled' => false]);
        $this->code(['code' => 'OLD-F3', 'startsAt' => '-10 days', 'endsAt' => '-1 day']);

        foreach (['NO-SUCH-CODE', 'off-f3', 'OLD-F3', 'x'] as $text) {
            [$shoes, , $cart] = $this->cart();

            $problem = $this->place(self::PATH, $this->body($cart, 25_830, $text), key: 'key-'.$text);

            $this->assertConflict('discount-code-invalid', 'Ten kod jest nieprawidłowy albo nieaktywny.', $problem);
            self::assertArrayNotHasKey('minimumOrderGross', $problem);
            $this->assertNothingPlaced($cart, $shoes);
        }
    }

    public function testACodeUsedUpIsAConflictWithoutAnOrderOrAUsage(): void
    {
        $code = $this->code(['code' => 'ONCE-F4', 'usageLimit' => 1]);
        [, , $first] = $this->cart();
        $this->place(self::PATH, $this->body($first, 25_830 - 2_583, 'ONCE-F4'), key: 'key-first');
        self::assertResponseStatusCodeSame(201);
        [$shoes, , $cart] = $this->cart();

        $this->assertConflict('discount-code-used-up', 'Ten kod został już wykorzystany.', $this->place(self::PATH, $this->body($cart, 25_830 - 2_583, 'ONCE-F4'), key: 'key-second'));

        $this->assertNothingPlaced($cart, $shoes, ordersBefore: 1);
        self::assertSame(1, $this->activeUses($code));
    }

    public function testASubtotalBelowTheMinimumIsAConflictWithTheMinimum(): void
    {
        $this->code(['code' => 'BIG-F5', 'minimumOrderGross' => 30_000]);
        [$shoes, , $cart] = $this->cart();

        $problem = $this->place(self::PATH, $this->body($cart, 25_830, 'BIG-F5'));

        $this->assertConflict('discount-code-below-minimum', 'Ten kod działa przy zamówieniu od 300,00 zł.', $problem);
        self::assertSame(30_000, $problem['minimumOrderGross'] ?? null);
        $this->assertNothingPlaced($cart, $shoes);
    }

    public function testAnExpectedTotalBeforeTheDiscountIsAConflict(): void
    {
        $this->code(['code' => 'WITAJ-F6']);
        [$shoes, , $cart] = $this->cart();

        $this->assertConflict('order-total-changed', 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', $this->place(self::PATH, $this->body($cart, 25_830, 'WITAJ-F6')));

        $this->assertNothingPlaced($cart, $shoes);
    }

    public function testABlankCodeIsNoCode(): void
    {
        foreach (['missing' => null, 'null' => 'null', 'empty' => '', 'spaces' => " \u{00A0}\t"] as $case => $text) {
            [, , $cart] = $this->cart();
            $body = $this->body($cart, 25_830, 'null' === $text ? null : $text);
            if ('missing' === $case) {
                unset($body['discountCode']);
            }

            $order = $this->place(self::PATH, $body, key: 'key-'.$case);

            self::assertResponseStatusCodeSame(201, $case);
            self::assertNull($order['discount'], $case);
            self::assertIsArray($order['totals']);
            self::assertSame(25_830, $order['totals']['gross'], $case);
        }
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage'));
    }

    public function testAReplayWithTheSameKeyGivesTheSameOrderWithoutASecondUsage(): void
    {
        $code = $this->code(['code' => 'WITAJ-F7']);
        [, , $cart] = $this->cart();
        $first = $this->place(self::PATH, $this->body($cart, 23_247, 'WITAJ-F7'));
        self::assertResponseStatusCodeSame(201);

        $replayed = $this->place(self::PATH, $this->body($cart, 23_247, 'witaj-f7'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first['orderId'], $replayed['orderId']);
        self::assertSame($first['discount'], $replayed['discount']);
        self::assertSame(1, $this->activeUses($code));
        $this->assertKeyReused($this->place(self::PATH, $this->body($cart, 25_830)), $first);
    }

    public function testTheExpiryReleasesTheUsage(): void
    {
        $code = $this->code(['code' => 'WITAJ-F8', 'usageLimit' => 5]);
        [, , $cart] = $this->cart();
        $order = $this->place(self::PATH, $this->body($cart, 23_247, 'WITAJ-F8'));
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($order['orderId']);
        self::assertSame(1, $this->adminActiveUses($code));
        $this->connection->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '10 minutes' WHERE id = ?", [$order['orderId']]);

        $this->commandBus()->dispatch(new Envelope(new ExpireUnpaidOrder($order['orderId']), [new ReceivedStamp('scheduled')]));
        $this->entityManager->clear();

        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$order['orderId']]));
        self::assertSame(0, $this->adminActiveUses($code), 'the panel counts the active usages without it');
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage WHERE order_id = ? AND released_at IS NOT NULL', [$order['orderId']]));
    }

    public function testACustomerPlacesAnOrderWithACodeAndSeesTheDiscountInTheHistory(): void
    {
        $this->code(['code' => 'KLIENT-F9', 'kind' => DiscountKind::Amount, 'value' => 5_000, 'minimumOrderGross' => 20_000]);
        [$shoes, $map] = $this->products();
        CartFixture::cart($this->connection, customerId: 'kc-sub-discount', lines: [[$shoes, 2], [$map, 1]]);
        $token = TestTokenFactory::customer(['sub' => 'kc-sub-discount']);

        $order = $this->place(self::CUSTOMER_PATH, [...self::details(20_830), 'discountCode' => 'klient-f9'], token: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($order['orderId']);
        $this->client->request('GET', self::CUSTOMER_PATH.'/'.$order['orderId'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($detail);
        self::assertMatchesSchema('CustomerOrder', $detail);
        self::assertSame(['code' => 'KLIENT-F9', 'kind' => 'amount', 'value' => 5_000, 'amountGross' => 5_000], $detail['discount']);
        self::assertSame(5_000, self::int(self::line($detail, 0)['discountGross']) + self::int(self::line($detail, 1)['discountGross']));
        self::assertArrayNotHasKey('promotion', self::line($detail, 0), 'the store does not see the promotion');
        self::assertIsArray($detail['totals']);
        self::assertSame(20_830, $detail['totals']['gross']);
    }

    public function testThePanelSeesTheDiscountAndThePromotionOfALine(): void
    {
        $this->code(['code' => 'PANEL-F10']);
        [$shoes, $map] = $this->products();
        $promotion = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6f10';
        $this->connection->executeStatement("INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, 'Jesień', 20, NOW() - interval '1 hour', NOW() + interval '1 day', NOW(), NOW(), 1)", [$promotion]);
        $this->connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$promotion, $shoes]);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2], [$map, 1]]);
        // 2 × 98,40 (123,00 less 20 %) + 12,30 = 209,10 zł; 10 % is 20,91 zł.
        $order = $this->place(self::PATH, $this->body($cart, 20_910 - 2_091, 'PANEL-F10'));
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($order['orderId']);

        $this->client->request('GET', '/api/v1/admin/orders/'.$order['orderId'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['support'])]);

        self::assertResponseIsSuccessful();
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($detail);
        self::assertSame(['code' => 'PANEL-F10', 'kind' => 'percent', 'value' => 10, 'amountGross' => 2_091], $detail['discount']);
        self::assertSame(['name' => 'Jesień', 'percentOff' => 20, 'regularUnitGross' => 12_300], self::line($detail, 0)['promotion']);
        self::assertSame([9_840, 19_680], [self::line($detail, 0)['unitGross'], self::line($detail, 0)['grossBeforeDiscount']]);
        self::assertNull(self::line($detail, 1)['promotion']);
        self::assertIsArray($detail['totals']);
        self::assertSame(18_819, $detail['totals']['gross']);
    }

    /**
     * @param array<mixed> $order
     *
     * @return array<mixed>
     */
    private static function line(array $order, int $index): array
    {
        self::assertIsArray($order['lines'] ?? null);
        $line = $order['lines'][$index] ?? null;
        self::assertIsArray($line);

        return $line;
    }

    private static function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function code(array $attributes): DiscountCode
    {
        $code = DiscountCodeFactory::createOne($attributes);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code;
    }

    /**
     * @return array{string, string}
     */
    private function products(): array
    {
        return [$this->variant(12_300, 10, 'Buty Puls'), $this->variant(1_230, 10, 'Mapa')];
    }

    /**
     * A guest cart of 2 × 123,00 zł and 1 × 12,30 zł (258,30 zł).
     *
     * @return array{string, string, string}
     */
    private function cart(): array
    {
        [$shoes, $map] = $this->products();

        return [$shoes, $map, CartFixture::cart($this->connection, lines: [[$shoes, 2], [$map, 1]])];
    }

    private function assertNothingPlaced(string $cart, string $shoes, int $ordersBefore = 0): void
    {
        self::assertSame($ordersBefore, $this->orders());
        self::assertSame($ordersBefore, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage'));
        self::assertCount(2, CartFixture::lines($this->connection, $cart), 'the cart keeps its lines');
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $shoes), 'nothing reserved');
    }

    private function activeUses(DiscountCode $code): int
    {
        return OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage WHERE code_id = ? AND released_at IS NULL', [$code->id()->toString()]);
    }

    private function adminActiveUses(DiscountCode $code): mixed
    {
        $this->client->request('GET', '/api/v1/admin/discount-codes/'.$code->id()->toString(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['support'])]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body['activeUses'] ?? null;
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
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
    private function body(string $cartId, int $expectedTotalGross, ?string $discountCode = null): array
    {
        return ['cartId' => $cartId, 'email' => self::EMAIL, ...self::details($expectedTotalGross), 'discountCode' => $discountCode];
    }
}
