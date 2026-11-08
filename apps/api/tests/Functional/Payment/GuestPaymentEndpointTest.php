<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/orders/{orderId}/payments and GET /api/v1/orders/{orderId}/payment: a guest starts and reads the
 * payment of an order with its access token, against the simulator in the same process.
 */
final class GuestPaymentEndpointTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait;

    public function testAGuestStartsAPaymentAtDemoTransfer(): void
    {
        $order = $this->guestOrder();

        $payment = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);

        self::assertResponseStatusCodeSame(201);
        $this->assertNoStore();
        $transaction = $this->transaction($this->assertStarted($payment, 'demo-transfer'));
        self::assertSame('demo-transfer', $transaction['provider']);
        self::assertSame($order['number'], $transaction['reference']);
        self::assertSame(self::GROSS, $transaction['amount']);
        self::assertSame('open', $transaction['status']);
        self::assertSame(rtrim((string) getenv('STORE_URL'), '/').'/checkout/confirmation', $transaction['return_url'], 'the API builds the address of the return');
        self::assertSame(
            $this->connection->fetchOne('SELECT payment_deadline FROM ordering_order WHERE id = ?', [$order['orderId']]),
            $transaction['expires_at'],
            'the session expires at the deadline of the order',
        );
        self::assertSame(1, $this->payments());
    }

    public function testAGuestStartsAPaymentAtDemoCard(): void
    {
        $order = $this->guestOrder();

        $payment = $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], accessToken: $order['accessToken']);

        self::assertResponseStatusCodeSame(201);
        $transaction = $this->transaction($this->assertStarted($payment, 'demo-card'));
        self::assertSame(self::GROSS, $transaction['amount'], '"123.00" in the API of demo-card');
        self::assertSame($transaction['success_url'], $transaction['cancel_url']);
        self::assertIsString($transaction['success_url']);
        self::assertStringEndsWith('/checkout/confirmation', $transaction['success_url']);
    }

    public function testARepeatAnswers200WithTheSamePayment(): void
    {
        $order = $this->guestOrder();
        $first = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);

        $repeated = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($first, $repeated);
        self::assertSame(1, $this->payments());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM payment_simulator_transaction'), 'no second session');
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function otherTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'an empty token' => [''];
        yield 'another token' => ['dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM'];
    }

    #[DataProvider('otherTokens')]
    public function testARepeatWithTheSameKeyWithoutTheTokenIsNotFound(?string $token): void
    {
        $order = $this->guestOrder();
        $first = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);

        $this->assertOrderNotFound($this->startPayment(self::startPath($order), accessToken: $token));

        self::assertIsString($first['paymentId']);
        self::assertStringNotContainsString($first['paymentId'], (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->payments());
    }

    public function testEveryCaseWithoutAccessIsNotFound(): void
    {
        $order = $this->guestOrder();
        $customerOrder = $this->customerOrder();

        $this->assertOrderNotFound($this->startPayment('/api/v1/orders/0192f0c4-7c1a-7c4e-9a6e-0000000000ff/payments', accessToken: $order['accessToken']));
        $this->assertOrderNotFound($this->startPayment('/api/v1/orders/R-100001/payments', accessToken: $order['accessToken']));
        $this->assertOrderNotFound($this->startPayment(self::startPath($order)));
        $this->assertOrderNotFound($this->startPayment(self::startPath($order), accessToken: 'wrong'));
        $this->assertOrderNotFound($this->startPayment(self::startPath($customerOrder), accessToken: $order['accessToken']));
        $this->assertOrderNotFound($this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer()));

        self::assertSame(0, $this->payments());
    }

    /**
     * The store chooses the endpoint by its cookie: a guest who logged in after placing the order still pays it here.
     */
    public function testASessionChangesNothingForTheOrderOfAGuest(): void
    {
        $order = $this->guestOrder();

        $this->assertStarted($this->startPayment(self::startPath($order), accessToken: $order['accessToken'], bearer: TestTokenFactory::customer()), 'demo-transfer');
        self::assertResponseStatusCodeSame(201);
    }

    public function testAMissingOrMalformedKeyIsABadRequest(): void
    {
        $order = $this->guestOrder();

        foreach ([null, '', 'a key', str_repeat('k', 101)] as $key) {
            $problem = $this->startPayment(self::startPath($order), key: $key, accessToken: $order['accessToken']);
            $this->assertProblem(400, null, $problem);
            self::assertSame(['Idempotency-Key'], array_column(\is_array($problem['violations'] ?? null) ? $problem['violations'] : [], 'propertyPath'));
        }

        self::assertSame(0, $this->payments());
    }

    public function testAnInvalidTokenOfASessionIsUnauthorized(): void
    {
        $order = $this->guestOrder();

        $this->assertProblem(401, null, $this->startPayment(self::startPath($order), accessToken: $order['accessToken'], bearer: 'not-a-jwt'));
        $this->assertProblem(401, null, $this->readStatus(self::statusPath($order), $order['accessToken'], 'not-a-jwt'));
        self::assertSame(0, $this->payments());
    }

    public function testAPaidOrderIsAConflict(): void
    {
        $order = $this->guestOrder();
        $this->markOrderPaid($order['orderId']);

        $this->assertPaymentConflict('order-already-paid', 'Zamówienie jest już opłacone.', $this->startPayment(self::startPath($order), accessToken: $order['accessToken']));

        self::assertSame(0, $this->payments());
    }

    public function testAnOrderPastTheDeadlineOrCancelledIsAConflict(): void
    {
        $late = $this->guestOrder();
        $this->passTheDeadline($late['orderId']);
        $cancelled = $this->guestOrder();
        $this->cancelOrder($cancelled['orderId']);

        $this->assertPaymentConflict('payment-deadline-passed', 'Minął termin płatności za to zamówienie.', $this->startPayment(self::startPath($late), accessToken: $late['accessToken']));
        $this->assertPaymentConflict('payment-deadline-passed', 'Minął termin płatności za to zamówienie.', $this->startPayment(self::startPath($cancelled), key: 'other', accessToken: $cancelled['accessToken']));

        self::assertSame(0, $this->payments());
    }

    public function testTheKeyOfAnotherPaymentIsAConflict(): void
    {
        $order = $this->guestOrder();
        $other = $this->guestOrder();
        $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);

        $this->assertPaymentConflict('payment-idempotency-key-reused', 'Ten klucz żądania był już użyty dla innej płatności.', $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], accessToken: $order['accessToken']));
        $this->assertPaymentConflict('payment-idempotency-key-reused', 'Ten klucz żądania był już użyty dla innej płatności.', $this->startPayment(self::startPath($other), accessToken: $other['accessToken']));

        self::assertSame(1, $this->payments());
    }

    public function testANewAttemptCancelsThePendingPaymentAtItsProvider(): void
    {
        $order = $this->guestOrder();
        $first = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);
        $firstTransaction = $this->assertStarted($first, 'demo-transfer');

        $second = $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], key: 'second', accessToken: $order['accessToken']);

        self::assertResponseStatusCodeSame(201);
        $secondTransaction = $this->assertStarted($second, 'demo-card');
        self::assertIsString($first['paymentId']);
        self::assertSame('cancelled', $this->paymentStatus($first['paymentId']));
        self::assertSame('cancelled', $this->transaction($firstTransaction)['status'], 'the old session can no longer be paid');
        self::assertSame('open', $this->transaction($secondTransaction)['status']);
    }

    public function testANewAttemptAfterARejectionAtTheProviderFailsTheOldPayment(): void
    {
        $order = $this->guestOrder();
        $first = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);
        $this->connection->executeStatement("UPDATE payment_simulator_transaction SET status = 'rejected', decided_at = now() WHERE id = ?", [$this->assertStarted($first, 'demo-transfer')]);

        $second = $this->startPayment(self::startPath($order), key: 'second', accessToken: $order['accessToken']);

        self::assertResponseStatusCodeSame(201);
        $this->assertStarted($second, 'demo-transfer');
        self::assertIsString($first['paymentId']);
        self::assertSame('failed', $this->paymentStatus($first['paymentId']));
    }

    public function testANewAttemptWhenThePendingPaymentWasPaidAtItsProviderIsAConflict(): void
    {
        $order = $this->guestOrder();
        $first = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);
        $this->connection->executeStatement("UPDATE payment_simulator_transaction SET status = 'paid', decided_at = now() WHERE id = ?", [$this->assertStarted($first, 'demo-transfer')]);

        $this->assertPaymentConflict('payment-in-progress', 'Płatność została już wykonana. Czekamy na jej potwierdzenie.', $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], key: 'second', accessToken: $order['accessToken']));

        self::assertIsString($first['paymentId']);
        self::assertSame('pending', $this->paymentStatus($first['paymentId']), 'the webhook confirms it');
        self::assertSame(1, $this->payments());
    }

    public function testABodyThatIsNotJsonIsUnsupported(): void
    {
        $order = $this->guestOrder();

        $this->assertProblem(415, null, $this->startPayment(self::startPath($order), 'provider=demo-transfer', accessToken: $order['accessToken'], contentType: 'application/x-www-form-urlencoded'));
        self::assertSame(0, $this->payments());
    }

    /**
     * @return iterable<string, array{array<mixed>|string, string}>
     */
    public static function invalidProviders(): iterable
    {
        yield 'an empty body' => ['', 'Wybierz sposób płatności.'];
        yield 'no provider' => [[], 'Wybierz sposób płatności.'];
        yield 'null' => [['provider' => null], 'Wybierz sposób płatności.'];
        yield 'empty' => [['provider' => ''], 'Wybierz sposób płatności.'];
        yield 'unknown' => [['provider' => 'paypal'], 'Nieznany sposób płatności.'];
        yield 'in upper case' => [['provider' => 'DEMO-CARD'], 'Nieznany sposób płatności.'];
        yield 'a number' => [['provider' => 5], 'Nieznany sposób płatności.'];
    }

    /**
     * @param array<mixed>|string $body
     */
    #[DataProvider('invalidProviders')]
    public function testAMissingOrUnknownProviderIsInvalid(array|string $body, string $title): void
    {
        $order = $this->guestOrder();

        $this->assertViolations([['provider', $title]], $this->startPayment(self::startPath($order), $body, accessToken: $order['accessToken']));
        self::assertSame(0, $this->payments());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unavailableProviders(): iterable
    {
        yield 'server error' => [new MockResponse('{"error":"boom"}', ['http_code' => 500])];
        yield 'timeout' => [new MockResponse('', ['error' => 'Idle timeout reached.'])];
        yield 'not JSON' => [new MockResponse('<html>', ['http_code' => 201])];
    }

    #[DataProvider('unavailableProviders')]
    public function testAProviderThatDoesNotAnswerProperlyIsUnavailableAndNothingIsStarted(MockResponse $response): void
    {
        $order = $this->guestOrder();
        $this->providerAnswers($response);

        $this->assertUnavailable($this->startPayment(self::startPath($order), accessToken: $order['accessToken']));

        self::assertSame(0, $this->payments());
    }

    public function testAProviderThatCannotCancelThePendingPaymentIsUnavailableAndNothingChanges(): void
    {
        $order = $this->guestOrder();
        $first = $this->startPayment(self::startPath($order), accessToken: $order['accessToken']);
        $this->providerAnswers(new MockResponse('', ['http_code' => 502]));

        $this->assertUnavailable($this->startPayment(self::startPath($order), key: 'second', accessToken: $order['accessToken']));

        self::assertIsString($first['paymentId']);
        self::assertSame('pending', $this->paymentStatus($first['paymentId']));
        self::assertSame(1, $this->payments());
    }

    public function testTheStatusOfAnOrderWithoutAPayment(): void
    {
        $order = $this->guestOrder();

        $status = $this->readStatus(self::statusPath($order), $order['accessToken']);

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('PaymentStatusView', $status);
        self::assertSame($order['number'], $status['orderNumber']);
        self::assertSame('new', $status['orderStatus']);
        self::assertSame(self::GROSS, $status['totalGross']);
        self::assertSame('PLN', $status['currency']);
        self::assertTrue($status['canStartPayment']);
        self::assertNull($status['latestPayment']);
        self::assertIsString($status['paymentDeadline']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $status['paymentDeadline'], 'RFC 3339 in UTC');
    }

    public function testThePendingPaymentCanBeResumedUntilTheDeadline(): void
    {
        $order = $this->guestOrder();
        $payment = $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], accessToken: $order['accessToken']);

        $status = $this->readStatus(self::statusPath($order), $order['accessToken']);

        self::assertMatchesSchema('PaymentStatusView', $status);
        self::assertSame(['provider' => 'demo-card', 'status' => 'pending', 'resumeUrl' => $payment['redirectUrl']], $status['latestPayment']);
        self::assertTrue($status['canStartPayment']);

        $this->passTheDeadline($order['orderId']);
        $late = $this->readStatus(self::statusPath($order), $order['accessToken']);

        self::assertFalse($late['canStartPayment']);
        self::assertSame(['provider' => 'demo-card', 'status' => 'pending', 'resumeUrl' => null], $late['latestPayment']);
    }

    public function testTheStatusOfAPaidOrder(): void
    {
        $order = $this->guestOrder();
        $this->markOrderPaid($order['orderId']);

        $status = $this->readStatus(self::statusPath($order), $order['accessToken']);

        self::assertSame('paid', $status['orderStatus']);
        self::assertFalse($status['canStartPayment']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesAfterThePayment(): iterable
    {
        yield 'shipped' => ['shipped'];
        yield 'delivered' => ['delivered'];
        yield 'returned' => ['returned'];
    }

    #[DataProvider('statusesAfterThePayment')]
    public function testAnOrderTakenOnAfterThePaymentIsPaidForPayment(string $orderStatus): void
    {
        $order = $this->guestOrder();
        $this->markOrderPaid($order['orderId']);
        $this->advancePaidOrder($order['orderId'], $orderStatus);

        $status = $this->readStatus(self::statusPath($order), $order['accessToken']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('paid', $status['orderStatus']);
        self::assertFalse($status['canStartPayment']);
        $this->assertPaymentConflict('order-already-paid', 'Zamówienie jest już opłacone.', $this->startPayment(self::startPath($order), accessToken: $order['accessToken']));
    }

    public function testTheStatusWithoutAccessIsNotFound(): void
    {
        $order = $this->guestOrder();
        $customerOrder = $this->customerOrder();

        $this->assertOrderNotFound($this->readStatus(self::statusPath($order)));
        $this->assertOrderNotFound($this->readStatus(self::statusPath($order), 'wrong'));
        $this->assertOrderNotFound($this->readStatus('/api/v1/orders/0192f0c4-7c1a-7c4e-9a6e-0000000000ff/payment', $order['accessToken']));
        $this->assertOrderNotFound($this->readStatus('/api/v1/orders/not-a-uuid/payment', $order['accessToken']));
        $this->assertOrderNotFound($this->readStatus(self::statusPath($customerOrder), $order['accessToken']));
    }

    public function testTheProvidersAreListedWithoutAToken(): void
    {
        $providers = $this->readStatus('/api/v1/payment-providers');

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame([
            ['code' => 'demo-transfer', 'name' => 'Szybki przelew (demo)'],
            ['code' => 'demo-card', 'name' => 'Karta płatnicza (demo)'],
        ], $providers);
        foreach ($providers as $provider) {
            self::assertMatchesSchema('PaymentProvider', $provider);
        }
    }

    public function testAWebhookPathNeedsNoTokenOfASession(): void
    {
        $this->client->request('POST', '/api/v1/payments/webhooks/demo-transfer');

        self::assertResponseStatusCodeSame(400, 'public (no 401): the endpoint refuses the missing signature (WebhookEndpointTest)');
        $this->assertNoStore();
    }

    /**
     * @param array{orderId: string} $order
     */
    private static function startPath(array $order): string
    {
        return '/api/v1/orders/'.$order['orderId'].'/payments';
    }

    /**
     * @param array{orderId: string} $order
     */
    private static function statusPath(array $order): string
    {
        return '/api/v1/orders/'.$order['orderId'].'/payment';
    }
}
