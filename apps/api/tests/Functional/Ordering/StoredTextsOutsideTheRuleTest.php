<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Domain\OrderShipped;
use App\Tests\Functional\Payment\PaymentEndpointTestTrait;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * Orders stored before the rule of texts with a contact or an address the rule refuses (spec api-input-contract, E7,
 * "Odczyt zapisanych wierszy"): the reads of the panel and of the customer give the texts as they are stored, and the
 * order is still paid by a webhook, shipped by a staff member, expired, and its emails go out. On shop_test, rolled
 * back after each test; the emails go to Mailpit.
 */
final class StoredTextsOutsideTheRuleTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait;

    /**
     * @return iterable<string, array{string, string}> the column and the text stored in it
     */
    public static function texts(): iterable
    {
        yield 'U+00A0 as the full name' => ['full_name', "\u{A0}"];
        yield 'U+009F in the full name' => ['full_name', "Anna\u{9F}Nowak"];
        yield 'U+200B as the street' => ['street', "\u{200B}"];
        yield 'U+00A0 at the ends of the street' => ['street', "\u{A0}ul. Długa 5/3\u{A0}"];
        yield 'a tab in the city' => ['city', "War\tszawa"];
    }

    #[DataProvider('texts')]
    public function testAnOrderWithAStoredTextOutsideTheRuleIsReadPaidShippedAndMailedUnchanged(string $column, string $text): void
    {
        ['orderId' => $orderId] = $this->customerOrder();
        $this->connection->executeStatement(\sprintf('UPDATE ordering_order SET %s = ? WHERE id = ?', $column), [$text, $orderId]);
        $stored = $this->storedTexts($orderId);
        self::assertSame($text, $stored[$column]);

        $list = $this->get('/api/v1/admin/orders', self::admin());
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($list['items']);
        self::assertContains([$orderId, $stored['full_name']], array_map(static fn (mixed $item): array => \is_array($item) ? [$item['orderId'], $item['fullName']] : [], $list['items']));
        $this->assertTextsOf($stored, $this->get('/api/v1/admin/orders/'.$orderId, self::admin()));
        $this->assertTextsOf($stored, $this->get('/api/v1/me/orders/'.$orderId, TestTokenFactory::customer()), withName: false);

        $this->handle(new OrderPlaced($orderId, new \DateTimeImmutable()));
        $this->pay($orderId);
        $this->handle(new OrderPaid($orderId, new \DateTimeImmutable()));

        $this->client->request('POST', '/api/v1/admin/orders/'.$orderId.'/status-changes', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::admin()], content: json_encode(['transition' => 'ship', 'version' => 2, 'note' => null, 'trackingNumber' => null], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $this->handle(new OrderShipped($orderId, new \DateTimeImmutable()));

        self::assertSame('shipped', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        foreach (['confirmation', 'payment_received', 'shipped'] as $kind) {
            self::assertNotNull(OrderingFixture::notificationSentAt($this->connection, $orderId, $kind), $kind);
        }
        self::assertSame($stored, $this->storedTexts($orderId), 'the commands keep the texts as stored');
    }

    #[DataProvider('texts')]
    public function testAnUnpaidOrderWithAStoredTextOutsideTheRuleExpiresAndItsEmailGoesOut(string $column, string $text): void
    {
        ['orderId' => $orderId] = $this->customerOrder();
        $this->connection->executeStatement(\sprintf('UPDATE ordering_order SET %s = ? WHERE id = ?', $column), [$text, $orderId]);
        $stored = $this->storedTexts($orderId);
        // Past the deadline and the window of a webhook delivered late (Order::expiryDueAt()).
        $this->connection->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '1 day' WHERE id = ?", [$orderId]);

        $this->bus('command.bus')->dispatch(new Envelope(new ExpireUnpaidOrder($orderId), [new ReceivedStamp('scheduled')]));
        $this->handle(new OrderCancelled($orderId, 'payment_timeout', new \DateTimeImmutable()));

        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertNotNull(OrderingFixture::notificationSentAt($this->connection, $orderId, 'cancelled'));
        self::assertSame($stored, $this->storedTexts($orderId));
    }

    /**
     * A payment started by the customer at the simulator, paid by the signed webhook of the provider.
     */
    private function pay(string $orderId): void
    {
        $payment = $this->startPayment('/api/v1/me/orders/'.$orderId.'/payments', bearer: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(201);
        $transaction = $this->assertStarted($payment, 'demo-transfer');
        $body = json_encode([
            'eventId' => 'evt_'.str_replace('-', '', $transaction),
            'transactionId' => $transaction,
            'merchantRef' => 'R-100001',
            'status' => 'PAID',
            'amount' => self::GROSS,
            'currency' => 'PLN',
            'paidAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $this->client->request('POST', '/api/v1/payments/webhooks/demo-transfer', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::secret('PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET')),
        ], content: $body);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
    }

    /**
     * Runs the handlers of the event as the consumer of `events` does (the test kernel keeps the messages in memory).
     */
    private function handle(object $event): void
    {
        $this->bus('event.bus')->dispatch(new Envelope($event, [new ReceivedStamp('events')]));
        $this->entityManager->clear();
    }

    private function bus(string $name): MessageBusInterface
    {
        $bus = self::getContainer()->get($name);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * @return array{full_name: string, street: string, city: string}
     */
    private function storedTexts(string $orderId): array
    {
        $row = $this->connection->fetchAssociative('SELECT full_name, street, city FROM ordering_order WHERE id = ?', [$orderId]);
        self::assertIsArray($row);
        \assert(\is_string($row['full_name']) && \is_string($row['street']) && \is_string($row['city']));

        return ['full_name' => $row['full_name'], 'street' => $row['street'], 'city' => $row['city']];
    }

    /**
     * @param array{full_name: string, street: string, city: string} $stored
     * @param array<mixed>                                           $order
     * @param bool                                                   $withName whether the view has the full name (the view of the customer has the address only)
     */
    private function assertTextsOf(array $stored, array $order, bool $withName = true): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['shippingAddress'] ?? null, (string) json_encode($order));
        $read = ['street' => $order['shippingAddress']['street'], 'city' => $order['shippingAddress']['city']];
        if ($withName) {
            $read = ['full_name' => $order['fullName'], ...$read];
        } else {
            unset($stored['full_name']);
        }
        self::assertSame($stored, $read);
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, string $token): array
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->decoded();
    }

    private static function admin(): string
    {
        return TestTokenFactory::staff(['admin']);
    }

    private static function secret(string $name): string
    {
        $secret = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        \assert(\is_string($secret) && '' !== $secret);

        return $secret;
    }
}
