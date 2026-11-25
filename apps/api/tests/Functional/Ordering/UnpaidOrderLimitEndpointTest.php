<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Tests\Functional\Payment\PaymentEndpointTestTrait;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/orders and /api/v1/me/orders with the limit of unpaid orders (ABUSE-LIMITS, L6, D6–D8) at the values
 * of when@test: 20 from one address, 20 of one email of a guest, 20 of one account. The earlier unpaid orders are
 * placed on command.bus with the address the controller would pass (the rate limit of the placement allows only 10
 * requests from one address in a test); the placement under test goes through HTTP from the address of the test
 * client (127.0.0.1). On shop_test, rolled back after each test.
 */
final class UnpaidOrderLimitEndpointTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait;

    private const int LIMIT = 20;
    private const string CLIENT = '127.0.0.1';
    private const string OTHER_CLIENT = '198.51.100.7';
    private const string EMAIL = 'anna.nowak@example.com';
    private const string CUSTOMER_EMAIL = 'jan.kowalski@example.com';
    private const string DETAIL = 'Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.';

    /** @var array<string, list<array{string, int}>> the lines of each guest cart when its checkout was loaded */
    private array $shown = [];

    private ?string $variant = null;

    public function testAnOrderOverTheLimitOfTheAddressIsAConflictAndChangesNothing(): void
    {
        $this->unpaidOrders(self::LIMIT, address: self::CLIENT);
        $cart = $this->cart();

        $problem = $this->placeGuest($cart);

        $this->assertConflict('too-many-unpaid-orders', self::DETAIL, $problem);
        self::assertSame(['type', 'title', 'status', 'detail'], array_keys($problem), 'nothing tells which limit');
        self::assertSame(self::LIMIT, $this->orders());
        self::assertSame(self::LIMIT, $this->claims());
        self::assertSame(self::LIMIT, $this->reservations());
        self::assertCount(1, CartFixture::lines($this->connection, $cart), 'the cart keeps its line');
    }

    public function testAnotherAddressPlacesItsOrder(): void
    {
        $this->unpaidOrders(self::LIMIT, address: self::CLIENT);

        $this->placeGuest($this->cart(), address: self::OTHER_CLIENT);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim WHERE address_key = ?', [self::OTHER_CLIENT]));
    }

    public function testAnOrderPaidByTheWebhookMakesRoomAndLosesItsClaim(): void
    {
        $orders = $this->unpaidOrders(self::LIMIT, address: self::CLIENT);
        $this->placeGuest($this->cart(), key: 'before');
        self::assertResponseStatusCodeSame(409);

        $this->payByWebhook($orders[0]);

        self::assertSame('paid', $this->orderStatus($orders[0]->order->orderId));
        self::assertSame(0, $this->claimsOf($orders[0]->order->orderId), 'a paid order has no claim');
        $this->placeGuest($this->cart(), key: 'after');
        self::assertResponseStatusCodeSame(201);
    }

    public function testAnExpiredOrderMakesRoomAndLosesItsClaim(): void
    {
        $orders = $this->unpaidOrders(self::LIMIT, address: self::CLIENT);

        $this->expire($orders[0]);

        self::assertSame('cancelled', $this->orderStatus($orders[0]->order->orderId));
        self::assertSame(0, $this->claimsOf($orders[0]->order->orderId), 'a cancelled order has no claim');
        $this->placeGuest($this->cart());
        self::assertResponseStatusCodeSame(201);
    }

    public function testEveryFormOfTheEmailCountsToItsLimitFromAnyAddress(): void
    {
        $forms = [self::EMAIL, 'ANNA.NOWAK@EXAMPLE.COM', 'anna.nowak+sklep@example.com', 'Anna.Nowak+X@Example.com'];
        $this->unpaidOrders(self::LIMIT, email: static fn (int $i): string => $forms[$i % 4], address: null);

        foreach (['  anna.nowak@example.com ', 'ANNA.Nowak+inny@example.com'] as $i => $email) {
            $problem = $this->placeGuest($this->cart(), email: $email, key: 'form-'.$i);

            $this->assertConflict('too-many-unpaid-orders', self::DETAIL, $problem);
        }
        $this->placeGuest($this->cart(), email: 'anna.nowak2@example.com', key: 'other-email');
        self::assertResponseStatusCodeSame(201);
    }

    public function testTheAccountPlacesItsOrderDespiteGuestOrdersWithItsEmail(): void
    {
        $this->unpaidOrders(self::LIMIT, email: static fn (): string => self::CUSTOMER_EMAIL, address: null);

        $this->placeCustomer();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim WHERE identity_key = ?', ['customer:'.TestTokenFactory::CUSTOMER_ID]));
    }

    public function testTheResponseIsTheSameForTheLimitOfTheAddressTheEmailAndTheAccount(): void
    {
        $this->unpaidOrders(self::LIMIT, address: self::OTHER_CLIENT);
        $address = $this->placeGuest($this->cart(), email: 'ewa@example.com', address: self::OTHER_CLIENT, key: 'address');
        $this->unpaidOrders(self::LIMIT, email: static fn (): string => 'piotr@example.com', address: null);
        $email = $this->placeGuest($this->cart(), email: 'piotr@example.com', key: 'email');
        $this->unpaidCustomerOrders(self::LIMIT);
        $account = $this->placeCustomer();

        $this->assertConflict('too-many-unpaid-orders', self::DETAIL, $address);
        self::assertSame($address, $email);
        self::assertSame($address, $account);
    }

    public function testAReplayAtAnExhaustedLimitIsTheOrderAlsoFromAnotherAddress(): void
    {
        $cart = $this->cart();
        $first = $this->placeGuest($cart, key: 'the-key');
        self::assertResponseStatusCodeSame(201);
        $this->unpaidOrders(self::LIMIT - 1, email: static fn (): string => self::EMAIL, address: self::CLIENT);

        foreach ([self::CLIENT, self::OTHER_CLIENT] as $address) {
            $replay = $this->placeGuest($cart, address: $address, key: 'the-key');

            self::assertResponseStatusCodeSame(200);
            self::assertSame($first['orderId'], $replay['orderId']);
        }
        $this->assertConflict('too-many-unpaid-orders', self::DETAIL, $this->placeGuest($this->cart(), key: 'a-new-key'));
    }

    public function testPaymentsOfAnExistingOrderWorkAtAnExhaustedLimitAndItsPaymentMakesRoom(): void
    {
        $orders = $this->unpaidOrders(self::LIMIT, email: static fn (): string => self::EMAIL, address: self::CLIENT);
        $order = $orders[0]->order;
        \assert(\is_string($order->accessToken));
        $path = '/api/v1/orders/'.$order->orderId.'/payments';

        $first = $this->startPayment($path, accessToken: $order->accessToken);
        self::assertResponseStatusCodeSame(201);
        $this->connection->executeStatement("UPDATE payment_simulator_transaction SET status = 'rejected', decided_at = now() WHERE id = ?", [$this->assertStarted($first, 'demo-transfer')]);
        $second = $this->startPayment($path, key: 'second', accessToken: $order->accessToken);
        self::assertResponseStatusCodeSame(201);
        $this->postWebhook($this->transferBody($this->assertStarted($second, 'demo-transfer'), $order->number));
        self::assertResponseStatusCodeSame(204);

        self::assertSame('paid', $this->orderStatus($order->orderId));
        $this->placeGuest($this->cart());
        self::assertResponseStatusCodeSame(201);
    }

    public function testPaidAndCancelledOrdersDoNotCountWhateverTheirNumber(): void
    {
        $orders = $this->unpaidOrders(self::LIMIT, email: static fn (): string => self::EMAIL, address: self::CLIENT);
        foreach ($orders as $i => $placed) {
            if (0 === $i % 2) {
                $this->dispatch(new MarkOrderPaid($placed->order->orderId, Uuid::v7()->toRfc4122(), $placed->order->totals->gross, 'PLN', new \DateTimeImmutable()));
            } else {
                $this->expire($placed);
            }
        }
        self::assertSame(0, $this->claims());

        $this->placeGuest($this->cart());

        self::assertResponseStatusCodeSame(201);
    }

    public function testAnOrderPastTheDeadlineCountsUntilItsClaimExpiresAlsoWhenTheWorkerIsLate(): void
    {
        $orders = $this->unpaidOrders(self::LIMIT, address: self::CLIENT);
        $this->connection->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '1 minute'");

        $this->assertConflict('too-many-unpaid-orders', self::DETAIL, $this->placeGuest($this->cart(), key: 'past-deadline'));

        // The worker has not cancelled the order (still new), but its claim expired.
        $this->connection->executeStatement("UPDATE ordering_unpaid_order_claim SET expires_at = now() - interval '1 second' WHERE order_id = ?", [$orders[0]->order->orderId]);
        self::assertSame('new', $this->orderStatus($orders[0]->order->orderId));
        $this->placeGuest($this->cart(), key: 'after-expiry');
        self::assertResponseStatusCodeSame(201);
    }

    public function testANewOrderHasItsClaimUntilItsExpiryAndNoViewTellsTheAddress(): void
    {
        $address = '203.0.113.77';
        $guest = $this->placeGuest($this->cart(), address: $address);
        self::assertResponseStatusCodeSame(201);
        $customer = $this->placeCustomer(address: $address);
        self::assertResponseStatusCodeSame(201);
        \assert(\is_string($guest['orderId']) && \is_string($customer['orderId']) && \is_string($guest['paymentDeadline']));

        self::assertSame(
            [$address, 'email:'.self::EMAIL, new \DateTimeImmutable($guest['paymentDeadline'])->modify('+5 minutes')->getTimestamp()],
            [...$this->connection->fetchFirstColumn('SELECT address_key FROM ordering_unpaid_order_claim WHERE order_id = ?', [$guest['orderId']]),
                $this->connection->fetchOne('SELECT identity_key FROM ordering_unpaid_order_claim WHERE order_id = ?', [$guest['orderId']]),
                OrderingFixture::count($this->connection, 'SELECT CAST(extract(epoch FROM expires_at) AS int) FROM ordering_unpaid_order_claim WHERE order_id = ?', [$guest['orderId']])],
            'the claim expires with the order (paymentDeadline + 5 minutes)',
        );

        $customerToken = TestTokenFactory::customer();
        $staff = TestTokenFactory::staff(['admin']);
        foreach ([
            ['/api/v1/me/orders', $customerToken],
            ['/api/v1/me/orders/'.$customer['orderId'], $customerToken],
            ['/api/v1/admin/orders', $staff],
            ['/api/v1/admin/orders/'.$guest['orderId'], $staff],
            ['/api/v1/admin/orders/'.$customer['orderId'], $staff],
        ] as [$path, $token]) {
            $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertStringNotContainsString($address, (string) $this->client->getResponse()->getContent(), $path.' tells nothing of the address');
        }
        self::assertStringNotContainsString($address, json_encode([$guest, $customer], \JSON_THROW_ON_ERROR), 'nor does the placement');
    }

    /**
     * Unpaid orders of guests placed on command.bus as the controller places them: each from a cart of its own, to the
     * email $email gives for its index (a new one each by default), from $address (a new one each when null).
     *
     * @param (\Closure(int): string)|null $email
     *
     * @return list<PlacedOrder>
     */
    private function unpaidOrders(int $count, ?\Closure $email = null, ?string $address = self::CLIENT): array
    {
        $placed = [];
        for ($i = 0; $i < $count; ++$i) {
            $cart = $this->cart();
            $result = $this->dispatch(new PlaceOrder(
                CartReference::guest($cart),
                null === $email ? 'buyer-'.bin2hex(random_bytes(4)).'@example.com' : $email($i),
                'Jan Kowalski',
                '600100200',
                'Długa 1',
                '00-950',
                'Warszawa',
                OrderingFixture::freeDeliveryMethod($this->connection),
                self::GROSS,
                OrderingFixture::expectedOfCart($this->connection, $cart),
                'earlier-'.Uuid::v7()->toRfc4122(),
                null,
                $address ?? \sprintf('192.0.2.%d', \count($placed) + 1),
            ));
            self::assertInstanceOf(PlacedOrder::class, $result);
            self::assertFalse($result->replayed);
            $placed[] = $result;
        }
        $this->entityManager->clear();

        return $placed;
    }

    /**
     * Unpaid orders of the customer of TestTokenFactory::customer(), each from another address.
     */
    private function unpaidCustomerOrders(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->refillCustomerCart();
            $result = $this->dispatch(new PlaceOrder(CartReference::customer(TestTokenFactory::CUSTOMER_ID), self::CUSTOMER_EMAIL, 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', OrderingFixture::freeDeliveryMethod($this->connection), self::GROSS, OrderingFixture::expectedOfCustomerCart($this->connection, TestTokenFactory::CUSTOMER_ID), 'earlier-'.Uuid::v7()->toRfc4122(), null, \sprintf('192.0.2.%d', 100 + $i)));
            self::assertInstanceOf(PlacedOrder::class, $result);
        }
        $this->entityManager->clear();
    }

    /**
     * @return array<mixed>
     */
    private function placeGuest(string $cart, string $email = self::EMAIL, string $address = self::CLIENT, string $key = self::KEY): array
    {
        $this->client->setServerParameter('REMOTE_ADDR', $address);
        // The checkout of a cart is loaded once: a repeat sends the lines it showed, also once the cart is emptied.
        $this->shown[$cart] ??= CartFixture::lines($this->connection, $cart);

        return $this->place('/api/v1/orders', ['cartId' => $cart, 'email' => $email, ...self::details(self::GROSS, $this->shown[$cart])], key: $key);
    }

    /**
     * @return array<mixed>
     */
    private function placeCustomer(string $address = self::CLIENT): array
    {
        $this->refillCustomerCart();
        $this->client->setServerParameter('REMOTE_ADDR', $address);
        $cart = $this->connection->fetchOne('SELECT id FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]);
        \assert(\is_string($cart));

        return $this->place('/api/v1/me/orders', self::details(self::GROSS, CartFixture::lines($this->connection, $cart)), key: 'customer-'.bin2hex(random_bytes(6)), token: TestTokenFactory::customer());
    }

    private function refillCustomerCart(): void
    {
        // The cart of the customer stays (emptied) after an order.
        $cart = $this->connection->fetchOne('SELECT id FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]);
        if (\is_string($cart)) {
            CartFixture::line($this->connection, $cart, $this->stockedVariant(), 1);
        } else {
            CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$this->stockedVariant(), 1]]);
        }
    }

    private function cart(): string
    {
        return CartFixture::cart($this->connection, lines: [[$this->stockedVariant(), 1]]);
    }

    private function stockedVariant(): string
    {
        return $this->variant ??= $this->variant(self::GROSS, 500);
    }

    private function payByWebhook(PlacedOrder $placed): void
    {
        \assert(\is_string($placed->order->accessToken));
        $payment = $this->startPayment('/api/v1/orders/'.$placed->order->orderId.'/payments', key: 'pay-'.$placed->order->orderId, accessToken: $placed->order->accessToken);
        self::assertResponseStatusCodeSame(201);

        $this->postWebhook($this->transferBody($this->assertStarted($payment, 'demo-transfer'), $placed->order->number));
        self::assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
    }

    /**
     * ExpireUnpaidOrder of the order once its expiry has come (its deadline moved six minutes back).
     */
    private function expire(PlacedOrder $placed): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '6 minutes' WHERE id = ?", [$placed->order->orderId]);
        $this->entityManager->clear();
        // As the worker receives it from its transport (dispatched without the stamp, it would be sent there).
        $this->dispatch(new Envelope(new ExpireUnpaidOrder($placed->order->orderId), [new ReceivedStamp('scheduled')]));
        $this->entityManager->clear();
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    private function orderStatus(string $orderId): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]);
    }

    private function claims(): int
    {
        return OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim');
    }

    private function claimsOf(string $orderId): int
    {
        return OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim WHERE order_id = ?', [$orderId]);
    }

    private function transferBody(string $transaction, string $number, string $status = 'PAID'): string
    {
        return json_encode([
            'eventId' => 'evt_'.str_replace('-', '', $transaction),
            'transactionId' => $transaction,
            'merchantRef' => $number,
            'status' => $status,
            'amount' => self::GROSS,
            'currency' => 'PLN',
            'paidAt' => new \DateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function postWebhook(string $body): void
    {
        $secret = $_SERVER['PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'] ?? $_ENV['PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'] ?? getenv('PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET');
        \assert(\is_string($secret) && '' !== $secret);
        $this->client->request('POST', '/api/v1/payments/webhooks/demo-transfer', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ], content: $body);
    }
}
