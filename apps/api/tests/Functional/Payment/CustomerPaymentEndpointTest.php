<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/me/orders/{orderId}/payments and GET /api/v1/me/orders/{orderId}/payment: the customer of the token
 * starts and reads the payment of own orders only.
 */
final class CustomerPaymentEndpointTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait;

    private const string OTHER_CUSTOMER = 'another-customer';

    public function testACustomerStartsAPaymentOfOwnOrder(): void
    {
        $order = $this->customerOrder();

        $payment = $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], bearer: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(201);
        $this->assertNoStore();
        $transaction = $this->transaction($this->assertStarted($payment, 'demo-card'));
        self::assertSame($order['number'], $transaction['reference']);
        self::assertSame(self::GROSS, $transaction['amount']);
    }

    public function testARepeatAnswers200WithTheSamePayment(): void
    {
        $order = $this->customerOrder();
        $first = $this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer());

        $repeated = $this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($first, $repeated);
        self::assertSame(1, $this->payments());
    }

    public function testTheSameKeyByAnotherCustomerIsNotFound(): void
    {
        $order = $this->customerOrder();
        $this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer());

        $this->assertOrderNotFound($this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer(['sub' => self::OTHER_CUSTOMER, 'email' => 'anna@example.com'])));

        self::assertSame(1, $this->payments());
    }

    public function testEveryCaseWithoutAccessIsNotFound(): void
    {
        $guestOrder = $this->guestOrder();
        $other = $this->customerOrder(self::OTHER_CUSTOMER);
        $token = TestTokenFactory::customer();

        $this->assertOrderNotFound($this->startPayment('/api/v1/me/orders/0192f0c4-7c1a-7c4e-9a6e-0000000000ff/payments', bearer: $token));
        $this->assertOrderNotFound($this->startPayment('/api/v1/me/orders/R-100001/payments', bearer: $token));
        $this->assertOrderNotFound($this->startPayment(self::startPath($other), bearer: $token));
        $this->assertOrderNotFound($this->startPayment(self::startPath($guestOrder), accessToken: $guestOrder['accessToken'], bearer: $token));
        $this->assertOrderNotFound($this->readStatus(self::statusPath($other), bearer: $token));
        $this->assertOrderNotFound($this->readStatus(self::statusPath($guestOrder), $guestOrder['accessToken'], $token));
        $this->assertOrderNotFound($this->readStatus('/api/v1/me/orders/not-a-uuid/payment', bearer: $token));

        self::assertSame(0, $this->payments());
    }

    public function testWithoutATokenOfASessionItIsUnauthorized(): void
    {
        $order = $this->customerOrder();

        $this->assertProblem(401, null, $this->startPayment(self::startPath($order)));
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        $this->assertProblem(401, null, $this->startPayment(self::startPath($order), bearer: TestTokenFactory::staff(['admin'])));
        $this->assertProblem(401, null, $this->readStatus(self::statusPath($order)));

        self::assertSame(0, $this->payments());
    }

    public function testAMissingKeyIsABadRequest(): void
    {
        $order = $this->customerOrder();

        $this->assertProblem(400, null, $this->startPayment(self::startPath($order), key: null, bearer: TestTokenFactory::customer()));
    }

    public function testEveryConflictOfAGuestIsAConflictOfACustomerToo(): void
    {
        $token = TestTokenFactory::customer();
        $paid = $this->customerOrder();
        $this->markOrderPaid($paid['orderId']);
        $this->assertPaymentConflict('order-already-paid', 'Zamówienie jest już opłacone.', $this->startPayment(self::startPath($paid), key: 'a', bearer: $token));

        $late = $this->customerOrder();
        $this->passTheDeadline($late['orderId']);
        $this->assertPaymentConflict('payment-deadline-passed', 'Minął termin płatności za to zamówienie.', $this->startPayment(self::startPath($late), key: 'b', bearer: $token));

        $order = $this->customerOrder();
        $first = $this->startPayment(self::startPath($order), key: 'c', bearer: $token);
        $this->assertPaymentConflict('payment-idempotency-key-reused', 'Ten klucz żądania był już użyty dla innej płatności.', $this->startPayment(self::startPath($order), ['provider' => 'demo-card'], key: 'c', bearer: $token));
        $this->connection->executeStatement("UPDATE payment_simulator_transaction SET status = 'paid', decided_at = now() WHERE id = ?", [$this->assertStarted($first, 'demo-transfer')]);
        $this->assertPaymentConflict('payment-in-progress', 'Płatność została już wykonana. Czekamy na jej potwierdzenie.', $this->startPayment(self::startPath($order), key: 'd', bearer: $token));
    }

    public function testAnInvalidProviderIsInvalidAndABodyThatIsNotJsonUnsupported(): void
    {
        $order = $this->customerOrder();

        $this->assertViolations([['provider', 'Nieznany sposób płatności.']], $this->startPayment(self::startPath($order), ['provider' => 'paypal'], bearer: TestTokenFactory::customer()));
        $this->assertProblem(415, null, $this->startPayment(self::startPath($order), '<provider/>', bearer: TestTokenFactory::customer(), contentType: 'application/xml'));
        self::assertSame(0, $this->payments());
    }

    public function testAnUnavailableProviderIsA503(): void
    {
        $order = $this->customerOrder();
        $this->providerAnswers(new MockResponse('', ['http_code' => 503]));

        $this->assertUnavailable($this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer()));
        self::assertSame(0, $this->payments());
    }

    public function testTheStatusOfOwnOrder(): void
    {
        $order = $this->customerOrder();
        $payment = $this->startPayment(self::startPath($order), bearer: TestTokenFactory::customer());

        $status = $this->readStatus(self::statusPath($order), bearer: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('PaymentStatusView', $status);
        self::assertSame($order['number'], $status['orderNumber']);
        self::assertTrue($status['canStartPayment']);
        self::assertSame(['provider' => 'demo-transfer', 'status' => 'pending', 'resumeUrl' => $payment['redirectUrl']], $status['latestPayment']);
    }

    /**
     * @param array{orderId: string} $order
     */
    private static function startPath(array $order): string
    {
        return '/api/v1/me/orders/'.$order['orderId'].'/payments';
    }

    /**
     * @param array{orderId: string} $order
     */
    private static function statusPath(array $order): string
    {
        return '/api/v1/me/orders/'.$order['orderId'].'/payment';
    }
}
