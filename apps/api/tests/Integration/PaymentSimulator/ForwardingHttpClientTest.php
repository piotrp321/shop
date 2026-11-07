<?php

declare(strict_types=1);

namespace App\Tests\Integration\PaymentSimulator;

use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Support\KernelForwardingHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client of the calls between the shop and the simulator in the tests (KernelForwardingHttpClient), on the
 * `test_messaging` kernel: a request reaches another instance of the kernel, with its headers and body, and what that
 * instance stores is in the database of the caller. The webhook of the shop does not exist in this task: the delivery
 * of the simulator reaches whatever answers at its path and a 4xx ends it.
 */
final class ForwardingHttpClientTest extends MessagingKernelTestCase
{
    private const string TRANSFER = '/payment-simulator/demo-transfer/api/transactions';

    public function testASessionCreatedThroughTheClientIsStoredByTheOtherKernelAndSeenByTheCaller(): void
    {
        $response = $this->client()->request('POST', $this->base().self::TRANSFER, [
            'auth_bearer' => $this->env('PAYMENT_DEMO_TRANSFER_API_KEY'),
            'json' => ['merchantRef' => 'R-100077', 'amount' => 4_200, 'currency' => 'PLN', 'returnUrl' => 'https://shop.test/back', 'expiresAt' => '2030-01-01T00:00:00Z'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $session = $response->toArray();
        self::assertSame(
            ['demo-transfer', 'R-100077', 4_200, 'open'],
            $this->connection()->fetchNumeric('SELECT provider, reference, amount, status FROM payment_simulator_transaction WHERE id = ?', [$session['transactionId']]),
        );
    }

    public function testTheAuthorizationAndTheBodyTravelWithTheRequest(): void
    {
        $unauthorized = $this->client()->request('POST', $this->base().self::TRANSFER, ['auth_bearer' => 'wrong', 'json' => []]);
        $invalid = $this->client()->request('POST', $this->base().self::TRANSFER, ['auth_bearer' => $this->env('PAYMENT_DEMO_TRANSFER_API_KEY'), 'body' => 'not json']);
        $card = $this->client()->request('POST', $this->base().'/payment-simulator/demo-card/v1/checkout-sessions/7d0e2a5e-0000-4000-8000-000000000000/expire', ['auth_basic' => ['merchant', $this->env('PAYMENT_DEMO_CARD_API_KEY')]]);

        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame(400, $invalid->getStatusCode());
        self::assertSame('body', $invalid->toArray(false)['field']);
        self::assertSame(404, $card->getStatusCode(), 'authenticated with Basic, the session is unknown');
        self::assertSame(['error' => 'not_found'], $card->toArray(false));
    }

    public function testThePaymentPageAndTheDecisionWorkThroughTheClient(): void
    {
        $session = $this->client()->request('POST', $this->base().'/payment-simulator/demo-card/v1/checkout-sessions', [
            'auth_basic' => ['merchant', $this->env('PAYMENT_DEMO_CARD_API_KEY')],
            'json' => ['reference' => 'R-100078', 'amount' => '42.00', 'currency' => 'PLN', 'successUrl' => 'https://shop.test/ok', 'cancelUrl' => 'https://shop.test/cancel', 'expiresAt' => 1_893_456_000],
        ])->toArray();
        $id = $session['id'];
        \assert(\is_string($id));

        $page = $this->client()->request('GET', $this->base().'/payment-simulator/demo-card/pay/'.$id);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('R-100078', $page->getContent());

        $decision = $this->client()->request('POST', $this->base().'/payment-simulator/demo-card/pay/'.$id, [
            'body' => ['_token' => hash_hmac('sha256', $id, $this->env('PAYMENT_DEMO_CARD_WEBHOOK_SECRET')), 'decision' => 'pay'],
            'max_redirects' => 0,
        ]);
        self::assertSame(303, $decision->getStatusCode());
        self::assertSame('https://shop.test/ok', $decision->getHeaders(false)['location'][0]);
        self::assertSame('paid', $this->connection()->fetchOne('SELECT status FROM payment_simulator_transaction WHERE id = ?', [$id]));
        self::assertSame(1, $this->rows('payment_simulator'), 'the other kernel wrote the delivery to the Doctrine transport');
    }

    public function testTheDeliveryOfTheSimulatorGoesThroughTheClientAndAClientErrorEndsIt(): void
    {
        $forwarding = KernelForwardingHttpClient::create('test_messaging');
        $delivered = [];
        // The delivery handler uses a spy around the forwarding client (a worker resets the services after a message,
        // which would also reset the count of the client itself).
        self::getContainer()->set('app.payment_http_client', new MockHttpClient(static function (string $method, string $url, array $options) use ($forwarding, &$delivered) {
            $delivered[] = $method.' '.$url;

            return $forwarding->request($method, $url, $options);
        }));
        $id = $forwarding->request('POST', $this->base().self::TRANSFER, [
            'auth_bearer' => $this->env('PAYMENT_DEMO_TRANSFER_API_KEY'),
            'json' => ['merchantRef' => 'R-100079', 'amount' => 4_200, 'currency' => 'PLN', 'returnUrl' => 'https://shop.test/back', 'expiresAt' => '2030-01-01T00:00:00Z'],
        ])->toArray()['transactionId'];
        \assert(\is_string($id));
        $decision = $forwarding->request('POST', $this->base().'/payment-simulator/demo-transfer/pay/'.$id, [
            'body' => ['_token' => hash_hmac('sha256', $id, $this->env('PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET')), 'decision' => 'pay'],
            'max_redirects' => 0,
        ]);
        self::assertSame(303, $decision->getStatusCode());

        $this->consume('payment_simulator');

        // Whatever answers at /api/v1/payments/webhooks/demo-transfer in the other kernel (no webhook endpoint
        // exists yet: a 401 or 404; later the endpoint itself: a 2xx or 4xx) must end the delivery.
        self::assertSame(['POST '.rtrim($this->env('PAYMENT_WEBHOOK_BASE_URL'), '/').'/api/v1/payments/webhooks/demo-transfer'], $delivered, 'the handler delivered through the client');
        self::assertSame(0, $this->rows('payment_simulator'));
        self::assertSame(0, $this->rows('failed'));
    }

    private function client(): MockHttpClient
    {
        $client = self::getContainer()->get('app.payment_http_client');
        self::assertInstanceOf(HttpClientInterface::class, $client);
        self::assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    private function base(): string
    {
        return rtrim($this->env('PAYMENT_PROVIDER_BASE_URL'), '/');
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, $name.' is not set: is the stack up (make up)?');

        return $value;
    }
}
