<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\ProviderSession;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Infrastructure\Provider\DemoCardProvider;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The adapter of demo-card: HTTP Basic (`merchant` and the key), amounts as "123.45", the expiry in Unix seconds; a
 * failure of any kind is PaymentProviderUnavailable.
 */
final class DemoCardProviderTest extends TestCase
{
    private const string KEY = 'card-api-key';
    private const string SESSION = '1e2d3c4b-5a69-4788-9a0b-c1d2e3f4a5b6';
    private const string URL = 'http://localhost:8000/payment-simulator/demo-card/pay/1e2d3c4b-5a69-4788-9a0b-c1d2e3f4a5b6';

    public function testCreatesACheckoutSessionAndReturnsItsPage(): void
    {
        $calls = new ProviderCalls(ProviderCalls::json(200, ['id' => self::SESSION, 'url' => self::URL]));

        $session = $this->create($calls);

        self::assertSame(self::SESSION, $session->providerPaymentId->toString());
        self::assertSame(self::URL, $session->redirectUrl);
        $request = $calls->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://api/payment-simulator/demo-card/v1/checkout-sessions', $request['url']);
        self::assertContains('Authorization: Basic '.base64_encode('merchant:'.self::KEY), $request['headers']);
        self::assertSame([
            'reference' => 'R-100001',
            'amount' => '123.45',
            'currency' => 'PLN',
            'successUrl' => 'http://localhost:3000/checkout/confirmation',
            'cancelUrl' => 'http://localhost:3000/checkout/confirmation',
            'expiresAt' => new \DateTimeImmutable('2026-11-05 12:30:00+00:00')->getTimestamp(),
        ], $calls->body());
        self::assertSame(5.0, $request['timeout']);
        self::assertSame(5.0, $request['max_duration']);
    }

    public function testTheCodeIsDemoCard(): void
    {
        self::assertSame(ProviderCode::DemoCard, new DemoCardProvider(new ProviderCalls()->client, 'http://api', self::KEY)->code());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function badCreations(): iterable
    {
        yield 'network error or timeout' => [ProviderCalls::networkError()];
        yield 'server error' => [ProviderCalls::json(503, [])];
        yield 'unauthorized' => [ProviderCalls::json(401, ['error' => 'unauthorized'])];
        yield '201 instead of 200' => [ProviderCalls::json(201, ['id' => self::SESSION, 'url' => self::URL])];
        yield 'not JSON' => [ProviderCalls::text(200, 'ok')];
        yield 'no id' => [ProviderCalls::json(200, ['url' => self::URL])];
        yield 'an id of another format' => [ProviderCalls::json(200, ['id' => str_repeat('a', 101), 'url' => self::URL])];
        yield 'no page' => [ProviderCalls::json(200, ['id' => self::SESSION])];
        yield 'a page that is no URL' => [ProviderCalls::json(200, ['id' => self::SESSION, 'url' => 'pay here'])];
    }

    #[DataProvider('badCreations')]
    public function testAnyOtherAnswerToACreationIsUnavailable(MockResponse $response): void
    {
        try {
            $this->create(new ProviderCalls($response));
            self::fail('A bad answer opened a session.');
        } catch (PaymentProviderUnavailable $unavailable) {
            self::assertStringNotContainsString(self::KEY, $unavailable->getMessage());
            self::assertStringContainsString('demo-card', $unavailable->getMessage());
        }
    }

    /**
     * @return iterable<string, array{MockResponse, SessionCancellation}>
     */
    public static function cancellations(): iterable
    {
        yield 'expired' => [ProviderCalls::json(200, ['status' => 'expired']), SessionCancellation::Cancelled];
        yield 'complete' => [ProviderCalls::json(409, ['status' => 'complete']), SessionCancellation::AlreadyPaid];
        yield 'failed' => [ProviderCalls::json(409, ['status' => 'failed']), SessionCancellation::AlreadyRejected];
    }

    #[DataProvider('cancellations')]
    public function testExpiresASession(MockResponse $response, SessionCancellation $expected): void
    {
        $calls = new ProviderCalls($response);

        self::assertSame($expected, $this->provider($calls)->cancelSession(ProviderPaymentId::fromString(self::SESSION)));

        $request = $calls->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://api/payment-simulator/demo-card/v1/checkout-sessions/'.self::SESSION.'/expire', $request['url']);
        self::assertContains('Authorization: Basic '.base64_encode('merchant:'.self::KEY), $request['headers']);
        self::assertSame(5.0, $request['timeout']);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function badCancellations(): iterable
    {
        yield 'network error or timeout' => [ProviderCalls::networkError()];
        yield 'server error' => [ProviderCalls::json(500, [])];
        yield 'unknown session' => [ProviderCalls::json(404, ['error' => 'not_found'])];
        yield '200 with another status' => [ProviderCalls::json(200, ['status' => 'complete'])];
        yield '409 with another status' => [ProviderCalls::json(409, ['status' => 'expired'])];
        yield 'statuses of demo-transfer' => [ProviderCalls::json(409, ['status' => 'PAID'])];
    }

    #[DataProvider('badCancellations')]
    public function testAnyOtherAnswerToACancellationIsUnavailable(MockResponse $response): void
    {
        $this->expectException(PaymentProviderUnavailable::class);

        $this->provider(new ProviderCalls($response))->cancelSession(ProviderPaymentId::fromString(self::SESSION));
    }

    private function create(ProviderCalls $calls): ProviderSession
    {
        return $this->provider($calls)->createSession(
            PaymentId::fromString(PaymentExamples::PAYMENT),
            PaymentExamples::amount(),
            'R-100001',
            'http://localhost:3000/checkout/confirmation',
            new \DateTimeImmutable('2026-11-05 12:30:00+00:00'),
        );
    }

    private function provider(ProviderCalls $calls): DemoCardProvider
    {
        return new DemoCardProvider($calls->client, 'http://api', self::KEY);
    }
}
