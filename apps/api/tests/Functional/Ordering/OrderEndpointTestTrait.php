<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Functional\Cart\CartEndpointTestTrait;
use App\Tests\Integration\Ordering\OrderingFixture;

/**
 * What the tests of the order endpoints share, on top of the cart helpers (client, variants with a price and a stock,
 * problem, violation, `Cache-Control` and schema checks): a placement with its `Idempotency-Key`, a valid body, and
 * the rows an order leaves. On shop_test, rolled back after each test.
 */
trait OrderEndpointTestTrait
{
    use CartEndpointTestTrait;

    private const string KEY = 'checkout-0192f0c4-7c1a-7c4e-9a6e-000000000001';

    /** What the order of a valid body keeps of the contact and the address (trimmed, the phone as +48 and 9 digits). */
    private const array CONTACT = [
        'fullName' => 'Anna Nowak',
        'phone' => '+48600100200',
        'shippingAddress' => ['street' => 'ul. Długa 5/3', 'postalCode' => '00-950', 'city' => 'Warszawa', 'country' => 'PL'],
    ];

    /**
     * POST of an order. An array body is sent as JSON (a float keeps its fraction: 2.0 stays 2.0), a string as it is;
     * a null key sends no header.
     *
     * @param array<mixed>|string $body
     *
     * @return array<mixed>
     */
    private function place(string $path, array|string $body, ?string $key = self::KEY, ?string $token = null, string $contentType = 'application/json'): array
    {
        $server = ['CONTENT_TYPE' => $contentType];
        if (null !== $key) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request('POST', $path, server: $server, content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION) : $body);
        $this->entityManager->clear();

        $content = (string) $this->client->getResponse()->getContent();
        $decoded = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The fields of the contact and the address of a valid body, as a customer sends them (with spaces to trim and
     * a phone with separators), with the given expected total.
     *
     * @return array<string, mixed>
     */
    private static function details(int $expectedTotalGross): array
    {
        return [
            'fullName' => '  Anna Nowak ',
            'phone' => '600 100-200',
            'street' => ' ul. Długa 5/3',
            'postalCode' => '00-950',
            'city' => 'Warszawa ',
            'expectedTotalGross' => $expectedTotalGross,
        ];
    }

    /**
     * @param array<mixed> $order
     */
    private function assertOrderView(array $order): void
    {
        self::assertMatchesSchema('OrderView', $order);
        self::assertIsString($order['orderId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $order['orderId']);
        self::assertIsString($order['number']);
        self::assertMatchesRegularExpression('/^R-[1-9][0-9]{5,}$/D', $order['number']);
        self::assertSame('new', $order['status']);
        self::assertIsString($order['placedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $order['placedAt'], 'RFC 3339 in UTC');
        self::assertEqualsWithDelta(time(), new \DateTimeImmutable($order['placedAt'])->getTimestamp(), 60);
        self::assertNull($this->response()->headers->get('Location'), 'an order cannot be read after it is placed');
    }

    private function orders(): int
    {
        return OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order');
    }

    private function reservations(): int
    {
        return OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM inventory_reservation');
    }

    /**
     * A 409 that has only the problem keys and the fixed detail, and none of the data of the order that used the key.
     *
     * @param array<mixed> $problem
     * @param array<mixed> $earlier the order that used the key
     */
    private function assertKeyReused(array $problem, array $earlier): void
    {
        $this->assertProblem(409, 'Ten klucz żądania był już użyty dla innego zamówienia.', $problem);
        self::assertSame(['type', 'title', 'status', 'detail'], array_keys($problem));
        $body = (string) $this->response()->getContent();
        foreach (['orderId', 'number', 'email'] as $field) {
            self::assertIsString($earlier[$field]);
            self::assertStringNotContainsString($earlier[$field], $body);
        }
    }
}
