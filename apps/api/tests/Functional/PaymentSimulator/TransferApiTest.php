<?php

declare(strict_types=1);

namespace App\Tests\Functional\PaymentSimulator;

use App\PaymentSimulator\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The API of the demo-transfer provider: Bearer key, JSON, amounts in minor units, times in RFC 3339.
 */
final class TransferApiTest extends WebTestCase
{
    use SimulatorEndpointTestTrait;

    private const string CREATE = '/payment-simulator/demo-transfer/api/transactions';

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
        yield 'a wrong key' => ['Bearer wrong-key'];
        yield 'an empty key' => ['Bearer '];
        yield 'basic instead of bearer' => ['Basic bWVyY2hhbnQ6d3Jvbmc='];
        yield 'the key without the scheme' => ['wrong-key'];
    }

    #[DataProvider('wrongCredentials')]
    public function testEveryEndpointRefusesAWrongKeyWith401(string $authorization): void
    {
        $transaction = $this->transfer();
        $before = $this->countTransactions();

        foreach ([self::CREATE, self::CREATE.'/'.$transaction->id.'/cancel'] as $url) {
            $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'] + ('' === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]), content: json_encode($this->validBody(), \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(401, $url);
            self::assertSame(['error' => 'unauthorized'], $this->json());
            self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        }
        self::assertSame($before, $this->countTransactions());
        self::assertSame(['open', false], $this->state($transaction->id));
    }

    public function testTheKeyOfTheOtherProviderIsRefused(): void
    {
        $this->post(self::CREATE, $this->validBody(), $this->apiKey(Provider::DemoCard));

        self::assertResponseStatusCodeSame(401);
    }

    public function testACreatedTransactionIsOpenAndAnswersWithItsPaymentPage(): void
    {
        $this->post(self::CREATE, $this->validBody());

        self::assertResponseStatusCodeSame(201);
        $answer = $this->json();
        self::assertSame(['transactionId', 'paymentUrl'], array_keys($answer));
        self::assertIsString($answer['transactionId']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $answer['transactionId'], 'a random UUID v4');
        self::assertSame(rtrim($this->env('PAYMENT_SIMULATOR_PUBLIC_URL'), '/').'/payment-simulator/demo-transfer/pay/'.$answer['transactionId'], $answer['paymentUrl']);
        $row = $this->connection()->fetchAssociative('SELECT provider, reference, amount, currency, return_url, success_url, cancel_url, status, decided_at, EXTRACT(EPOCH FROM expires_at)::bigint AS expires FROM payment_simulator_transaction WHERE id = ?', [$answer['transactionId']]);
        self::assertSame([
            'provider' => 'demo-transfer',
            'reference' => 'R-100001',
            'amount' => 12_345,
            'currency' => 'PLN',
            'return_url' => 'https://shop.test/checkout/confirmation',
            'success_url' => null,
            'cancel_url' => null,
            'status' => 'open',
            'decided_at' => null,
            'expires' => 1_793_968_200,
        ], $row);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> the body with one field changed (null removes it)
     */
    public static function invalidChanges(): iterable
    {
        yield 'no reference' => [['merchantRef' => null]];
        yield 'an empty reference' => [['merchantRef' => '']];
        yield 'a reference that is not a string' => [['merchantRef' => 100001]];
        yield 'a too long reference' => [['merchantRef' => str_repeat('R', 101)]];
        yield 'no amount' => [['amount' => null]];
        yield 'a zero amount' => [['amount' => 0]];
        yield 'a negative amount' => [['amount' => -1]];
        yield 'an amount with decimals' => [['amount' => 123.45]];
        yield 'an amount as a string' => [['amount' => '12345']];
        yield 'a lowercase currency' => [['currency' => 'pln']];
        yield 'a long currency' => [['currency' => 'PLNN']];
        yield 'no currency' => [['currency' => null]];
        yield 'no return url' => [['returnUrl' => null]];
        yield 'a return url that is not http' => [['returnUrl' => 'javascript:alert(1)']];
        yield 'a return url that is not a url' => [['returnUrl' => 'checkout']];
        yield 'no expiry' => [['expiresAt' => null]];
        yield 'an expiry that is not a time' => [['expiresAt' => 'tomorrow']];
        yield 'an expiry as a number' => [['expiresAt' => 1_793_968_200]];
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

    public function testABodyThatIsNotAJsonObjectIs400(): void
    {
        foreach (['', 'not json', '[1,2]', '"text"'] as $content) {
            $this->client->request('POST', self::CREATE, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->apiKey(Provider::DemoTransfer)], content: $content);

            self::assertResponseStatusCodeSame(400, $content);
        }
        self::assertSame(0, $this->countTransactions());
    }

    public function testAnOpenTransactionIsCancelledAndCancellingAgainSucceeds(): void
    {
        $transaction = $this->transfer();

        $this->post(self::CREATE.'/'.$transaction->id.'/cancel', null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'CANCELLED'], $this->json());
        self::assertSame(['cancelled', false], $this->state($transaction->id));

        $this->post(self::CREATE.'/'.$transaction->id.'/cancel', null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'CANCELLED'], $this->json());
        self::assertSame(['cancelled', false], $this->state($transaction->id));
    }

    public function testAPaidTransactionIsNotCancelledAndReportsPaid(): void
    {
        $transaction = $this->transfer();
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET status = 'paid', decided_at = now() WHERE id = ?", [$transaction->id]);

        $this->post(self::CREATE.'/'.$transaction->id.'/cancel', null);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['status' => 'PAID'], $this->json());
        self::assertSame(['paid', true], $this->state($transaction->id));
    }

    public function testARejectedTransactionIsNotCancelledAndReportsRejected(): void
    {
        $transaction = $this->transfer();
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET status = 'rejected', decided_at = now() WHERE id = ?", [$transaction->id]);

        $this->post(self::CREATE.'/'.$transaction->id.'/cancel', null);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['status' => 'REJECTED'], $this->json());
    }

    public function testAnUnknownTransactionAnotherProvidersAndAMalformedIdAreNotFound(): void
    {
        $card = $this->card();

        foreach (['7d0e2a5e-0000-4000-8000-000000000000', $card->id, 'not-a-uuid'] as $id) {
            $this->post(self::CREATE.'/'.$id.'/cancel', null);

            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame(['error' => 'not_found'], $this->json());
        }
        self::assertSame(['open', false], $this->state($card->id));
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->client->request('GET', self::CREATE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->apiKey(Provider::DemoTransfer)]);

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function post(string $url, ?array $body, ?string $key = null): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.($key ?? $this->apiKey(Provider::DemoTransfer))], content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'merchantRef' => 'R-100001',
            'amount' => 12_345,
            'currency' => 'PLN',
            'description' => 'Order R-100001',
            'returnUrl' => 'https://shop.test/checkout/confirmation',
            'expiresAt' => '2026-11-06T12:30:00Z',
        ];
    }
}
