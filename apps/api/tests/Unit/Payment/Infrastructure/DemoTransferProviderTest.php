<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\ProviderSession;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Infrastructure\Provider\DemoTransferProvider;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The adapter of demo-transfer: Bearer key, amounts in minor units, times in RFC 3339; a failure of any kind is
 * PaymentProviderUnavailable.
 */
final class DemoTransferProviderTest extends TestCase
{
    private const string KEY = 'transfer-api-key';
    private const string TRANSACTION = '7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70';
    private const string PAYMENT_URL = 'http://localhost:8000/payment-simulator/demo-transfer/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70';

    public function testCreatesATransactionAndReturnsItsPage(): void
    {
        $calls = new ProviderCalls(ProviderCalls::json(201, ['transactionId' => self::TRANSACTION, 'paymentUrl' => self::PAYMENT_URL]));

        $session = $this->create($calls);

        self::assertSame(self::TRANSACTION, $session->providerPaymentId->toString());
        self::assertSame(self::PAYMENT_URL, $session->redirectUrl);
        $request = $calls->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://api/payment-simulator/demo-transfer/api/transactions', $request['url']);
        self::assertContains('Authorization: Bearer '.self::KEY, $request['headers']);
        self::assertContains('Content-Type: application/json', $request['headers']);
        self::assertSame([
            'merchantRef' => 'R-100001',
            'amount' => 12_345,
            'currency' => 'PLN',
            'description' => 'Zamówienie R-100001',
            'returnUrl' => 'http://localhost:3000/checkout/confirmation',
            'expiresAt' => '2026-11-05T12:30:00+00:00',
        ], $calls->body());
        self::assertSame(5.0, $request['timeout']);
        self::assertSame(5.0, $request['max_duration']);
    }

    public function testTheCodeIsDemoTransfer(): void
    {
        self::assertSame(ProviderCode::DemoTransfer, new DemoTransferProvider(new ProviderCalls()->client, 'http://api', self::KEY)->code());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function badCreations(): iterable
    {
        yield 'network error or timeout' => [ProviderCalls::networkError()];
        yield 'server error' => [ProviderCalls::json(500, ['error' => 'boom'])];
        yield 'unauthorized' => [ProviderCalls::json(401, ['error' => 'unauthorized'])];
        yield 'invalid request' => [ProviderCalls::json(400, ['error' => 'invalid_request', 'field' => 'amount'])];
        yield '200 instead of 201' => [ProviderCalls::json(200, ['transactionId' => self::TRANSACTION, 'paymentUrl' => self::PAYMENT_URL])];
        yield 'not JSON' => [ProviderCalls::text(201, '<html>')];
        yield 'a JSON list' => [ProviderCalls::json(201, [self::TRANSACTION])];
        yield 'no id' => [ProviderCalls::json(201, ['paymentUrl' => self::PAYMENT_URL])];
        yield 'an id of another format' => [ProviderCalls::json(201, ['transactionId' => 'tx/1', 'paymentUrl' => self::PAYMENT_URL])];
        yield 'a numeric id' => [ProviderCalls::json(201, ['transactionId' => 1, 'paymentUrl' => self::PAYMENT_URL])];
        yield 'no page' => [ProviderCalls::json(201, ['transactionId' => self::TRANSACTION])];
        yield 'a page that is no http URL' => [ProviderCalls::json(201, ['transactionId' => self::TRANSACTION, 'paymentUrl' => 'javascript:alert(1)'])];
        yield 'a page longer than 500 characters' => [ProviderCalls::json(201, ['transactionId' => self::TRANSACTION, 'paymentUrl' => 'http://localhost/'.str_repeat('a', 484)])];
    }

    #[DataProvider('badCreations')]
    public function testAnyOtherAnswerToACreationIsUnavailable(MockResponse $response): void
    {
        try {
            $this->create(new ProviderCalls($response));
            self::fail('A bad answer opened a session.');
        } catch (PaymentProviderUnavailable $unavailable) {
            self::assertStringNotContainsString(self::KEY, $unavailable->getMessage());
            self::assertStringContainsString('demo-transfer', $unavailable->getMessage());
        }
    }

    /**
     * @return iterable<string, array{MockResponse, SessionCancellation}>
     */
    public static function cancellations(): iterable
    {
        yield 'cancelled' => [ProviderCalls::json(200, ['status' => 'CANCELLED']), SessionCancellation::Cancelled];
        yield 'paid' => [ProviderCalls::json(409, ['status' => 'PAID']), SessionCancellation::AlreadyPaid];
        yield 'rejected' => [ProviderCalls::json(409, ['status' => 'REJECTED']), SessionCancellation::AlreadyRejected];
    }

    #[DataProvider('cancellations')]
    public function testCancelsATransaction(MockResponse $response, SessionCancellation $expected): void
    {
        $calls = new ProviderCalls($response);

        self::assertSame($expected, $this->provider($calls)->cancelSession(ProviderPaymentId::fromString(self::TRANSACTION)));

        $request = $calls->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://api/payment-simulator/demo-transfer/api/transactions/'.self::TRANSACTION.'/cancel', $request['url']);
        self::assertContains('Authorization: Bearer '.self::KEY, $request['headers']);
        self::assertSame(5.0, $request['timeout']);
        self::assertSame(5.0, $request['max_duration']);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function badCancellations(): iterable
    {
        yield 'network error or timeout' => [ProviderCalls::networkError()];
        yield 'server error' => [ProviderCalls::json(500, ['status' => 'CANCELLED'])];
        yield 'unknown transaction' => [ProviderCalls::json(404, ['error' => 'not_found'])];
        yield '200 with another status' => [ProviderCalls::json(200, ['status' => 'PAID'])];
        yield '409 with another status' => [ProviderCalls::json(409, ['status' => 'CANCELLED'])];
        yield '409 without a status' => [ProviderCalls::json(409, [])];
        yield 'not JSON' => [ProviderCalls::text(200, 'CANCELLED')];
    }

    #[DataProvider('badCancellations')]
    public function testAnyOtherAnswerToACancellationIsUnavailable(MockResponse $response): void
    {
        $this->expectException(PaymentProviderUnavailable::class);

        $this->provider(new ProviderCalls($response))->cancelSession(ProviderPaymentId::fromString(self::TRANSACTION));
    }

    private function create(ProviderCalls $calls): ProviderSession
    {
        return $this->provider($calls)->createSession(
            PaymentId::fromString(PaymentExamples::PAYMENT),
            PaymentExamples::amount(),
            'R-100001',
            'http://localhost:3000/checkout/confirmation',
            new \DateTimeImmutable('2026-11-05 13:30:00+01:00'),
        );
    }

    private function provider(ProviderCalls $calls): DemoTransferProvider
    {
        return new DemoTransferProvider($calls->client, 'http://api/', self::KEY);
    }
}
