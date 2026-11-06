<?php

declare(strict_types=1);

namespace App\Tests\Functional\PaymentSimulator;

use App\PaymentSimulator\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The API of the demo-card provider: HTTP Basic (`merchant` and the key), amounts as "123.45", expiry in Unix seconds.
 */
final class CardApiTest extends WebTestCase
{
    use SimulatorEndpointTestTrait;

    private const string CREATE = '/payment-simulator/demo-card/v1/checkout-sessions';

    protected function setUp(): void
    {
        $this->startClient();
    }

    /**
     * @return iterable<string, array{string}> the Authorization header, '' for none
     */
    public static function wrongCredentials(): iterable
    {
        yield 'no header' => [''];
        yield 'a wrong key' => ['Basic bWVyY2hhbnQ6d3Jvbmc='];
        yield 'a wrong user' => ['Basic b3RoZXI6S0VZ'];
        yield 'bearer instead of basic' => ['Bearer wrong-key'];
        yield 'a header that is not base64' => ['Basic %%%'];
        yield 'no colon' => ['Basic bWVyY2hhbnQ='];
    }

    #[DataProvider('wrongCredentials')]
    public function testEveryEndpointRefusesWrongCredentialsWith401(string $authorization): void
    {
        $transaction = $this->card();
        $before = $this->countTransactions();

        foreach ([self::CREATE, self::CREATE.'/'.$transaction->id.'/expire'] as $url) {
            $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'] + ('' === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]), content: json_encode($this->validBody(), \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(401, $url);
            self::assertSame(['error' => 'unauthorized'], $this->json());
            self::assertResponseHeaderSame('WWW-Authenticate', 'Basic realm="demo-card"');
        }
        self::assertSame($before, $this->countTransactions());
        self::assertSame(['open', false], $this->state($transaction->id));
    }

    public function testTheKeyOfTheOtherProviderIsRefused(): void
    {
        $this->post(self::CREATE, $this->validBody(), $this->apiKey(Provider::DemoTransfer));

        self::assertResponseStatusCodeSame(401);
    }

    public function testACreatedSessionIsOpenAndAnswersWithItsPaymentPage(): void
    {
        $this->post(self::CREATE, $this->validBody());

        self::assertResponseStatusCodeSame(200);
        $answer = $this->json();
        self::assertSame(['id', 'url'], array_keys($answer));
        self::assertIsString($answer['id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $answer['id']);
        self::assertSame(rtrim($this->env('PAYMENT_SIMULATOR_PUBLIC_URL'), '/').'/payment-simulator/demo-card/pay/'.$answer['id'], $answer['url']);
        $row = $this->connection()->fetchAssociative('SELECT provider, reference, amount, currency, return_url, success_url, cancel_url, status, decided_at, EXTRACT(EPOCH FROM expires_at)::bigint AS expires FROM payment_simulator_transaction WHERE id = ?', [$answer['id']]);
        self::assertSame([
            'provider' => 'demo-card',
            'reference' => 'R-100001',
            'amount' => 12_345,
            'currency' => 'PLN',
            'return_url' => null,
            'success_url' => 'https://shop.test/checkout/confirmation?result=success',
            'cancel_url' => 'https://shop.test/checkout/confirmation?result=cancel',
            'status' => 'open',
            'decided_at' => null,
            'expires' => 1_793_968_200,
        ], $row);
    }

    public function testTheLastSecondOfTheYear9999IsStillAnAcceptedExpiry(): void
    {
        $this->post(self::CREATE, [...$this->validBody(), 'expiresAt' => 253_402_300_799]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> the body with one field changed (null removes it)
     */
    public static function invalidChanges(): iterable
    {
        yield 'no reference' => [['reference' => null]];
        yield 'an empty reference' => [['reference' => '']];
        yield 'a too long reference' => [['reference' => str_repeat('R', 101)]];
        yield 'no amount' => [['amount' => null]];
        yield 'an amount as a number' => [['amount' => 123.45]];
        yield 'an amount in minor units' => [['amount' => 12345]];
        yield 'an amount with one decimal place' => [['amount' => '1.5']];
        yield 'a negative amount' => [['amount' => '-1.00']];
        yield 'an amount with a leading zero' => [['amount' => '01.00']];
        yield 'an amount with a comma' => [['amount' => '1,00']];
        yield 'a zero amount' => [['amount' => '0.00']];
        yield 'a lowercase currency' => [['currency' => 'pln']];
        yield 'no success url' => [['successUrl' => null]];
        yield 'a success url that is not http' => [['successUrl' => 'javascript:alert(1)']];
        yield 'no cancel url' => [['cancelUrl' => null]];
        yield 'a cancel url that is not a url' => [['cancelUrl' => 'cancel']];
        yield 'no expiry' => [['expiresAt' => null]];
        yield 'an expiry as a string' => [['expiresAt' => '2026-11-06T12:30:00Z']];
        yield 'a negative expiry' => [['expiresAt' => -5]];
        yield 'an expiry with decimals' => [['expiresAt' => 1.5]];
        yield 'an expiry in the year 10000' => [['expiresAt' => 253_402_300_800]];
        yield 'an absurdly large expiry' => [['expiresAt' => 1_000_000_000_000_000]];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('invalidChanges')]
    public function testAnInvalidBodyIs400AndStoresNothing(array $change): void
    {
        $body = array_filter([...$this->validBody(), ...$change], static fn ($value) => null !== $value);

        $this->post(self::CREATE, $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error', 'field'], array_keys($this->json()));
        self::assertSame(0, $this->countTransactions());
    }

    public function testAnOpenSessionIsExpiredAndExpiringAgainSucceeds(): void
    {
        $transaction = $this->card();

        $this->post(self::CREATE.'/'.$transaction->id.'/expire', null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'expired'], $this->json());
        self::assertSame(['cancelled', false], $this->state($transaction->id));

        $this->post(self::CREATE.'/'.$transaction->id.'/expire', null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'expired'], $this->json());
    }

    public function testACompletedSessionIsNotExpiredAndReportsComplete(): void
    {
        $transaction = $this->card();
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET status = 'paid', decided_at = now() WHERE id = ?", [$transaction->id]);

        $this->post(self::CREATE.'/'.$transaction->id.'/expire', null);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['status' => 'complete'], $this->json());
        self::assertSame(['paid', true], $this->state($transaction->id));
    }

    public function testAFailedSessionIsNotExpiredAndReportsFailed(): void
    {
        $transaction = $this->card();
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET status = 'rejected', decided_at = now() WHERE id = ?", [$transaction->id]);

        $this->post(self::CREATE.'/'.$transaction->id.'/expire', null);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['status' => 'failed'], $this->json());
    }

    public function testAnUnknownSessionAnotherProvidersAndAMalformedIdAreNotFound(): void
    {
        $transfer = $this->transfer();

        foreach (['7d0e2a5e-0000-4000-8000-000000000000', $transfer->id, 'not-a-uuid'] as $id) {
            $this->post(self::CREATE.'/'.$id.'/expire', null);

            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame(['error' => 'not_found'], $this->json());
        }
        self::assertSame(['open', false], $this->state($transfer->id));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function post(string $url, ?array $body, ?string $key = null): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('merchant:'.($key ?? $this->apiKey(Provider::DemoCard)))], content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'reference' => 'R-100001',
            'amount' => '123.45',
            'currency' => 'PLN',
            'successUrl' => 'https://shop.test/checkout/confirmation?result=success',
            'cancelUrl' => 'https://shop.test/checkout/confirmation?result=cancel',
            'expiresAt' => 1_793_968_200,
        ];
    }
}
