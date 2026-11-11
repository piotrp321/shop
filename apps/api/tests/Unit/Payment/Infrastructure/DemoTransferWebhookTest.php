<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Payment\Application\InvalidWebhook;
use App\Payment\Application\ProviderEvent;
use App\Payment\Application\WebhookOutcome;
use App\Payment\Infrastructure\Provider\DemoTransferProvider;
use App\Tests\Support\WebhookSignatureVector as Vector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The webhooks of demo-transfer: `X-Signature` is the hex HMAC-SHA256 of the raw body with the webhook secret,
 * verified (hash_equals) before the body is read; `PAID` with `paidAt` (RFC 3339) succeeds, `REJECTED` fails; amounts
 * in minor units. Any fault is InvalidWebhook. The shared vector (WebhookSignatureVector) is what the simulator signs.
 */
final class DemoTransferWebhookTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:05+00:00';

    public function testTheSignedEventOfTheSharedVectorIsAPaymentExecutedAtPaidAt(): void
    {
        $event = $this->parse(['x-signature' => Vector::TRANSFER_SIGNATURE], Vector::TRANSFER_BODY);

        self::assertSame(Vector::EVENT_ID, $event->eventId->toString());
        self::assertSame(Vector::TRANSACTION_ID, $event->providerPaymentId->toString());
        self::assertSame(WebhookOutcome::Succeeded, $event->outcome);
        self::assertSame(Vector::AMOUNT, $event->amount->minorUnits());
        self::assertEquals(new \DateTimeImmutable('@'.Vector::EXECUTED_AT), $event->executedAt);
    }

    public function testARejectedPaymentFailsWithoutATimeOfExecution(): void
    {
        $body = self::body(['status' => 'REJECTED'], without: 'paidAt');

        $event = $this->parse(self::signed($body), $body);

        self::assertSame(WebhookOutcome::Failed, $event->outcome);
        self::assertNull($event->executedAt);
        self::assertSame(Vector::AMOUNT, $event->amount->minorUnits());
    }

    /**
     * @return iterable<string, array{string, \DateTimeImmutable}>
     */
    public static function paidAtFormats(): iterable
    {
        yield 'UTC with Z' => ['2026-11-06T12:00:00Z', new \DateTimeImmutable('2026-11-06 12:00:00 UTC')];
        yield 'an offset' => ['2026-11-06T13:00:00+01:00', new \DateTimeImmutable('2026-11-06 12:00:00 UTC')];
        yield 'a fraction of a second' => ['2026-11-06T12:00:00.250Z', new \DateTimeImmutable('2026-11-06 12:00:00.250 UTC')];
    }

    #[DataProvider('paidAtFormats')]
    public function testPaidAtIsReadAsRfc3339(string $paidAt, \DateTimeImmutable $expected): void
    {
        $body = self::body(['paidAt' => $paidAt]);

        $event = $this->parse(self::signed($body), $body);

        self::assertEquals($expected, $event->executedAt);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badSignatures(): iterable
    {
        yield 'no header' => [[], Vector::TRANSFER_BODY];
        yield 'an empty header' => [['x-signature' => ''], Vector::TRANSFER_BODY];
        yield 'another secret' => [['x-signature' => hash_hmac('sha256', Vector::TRANSFER_BODY, 'another-secret')], Vector::TRANSFER_BODY];
        yield 'a changed body' => [['x-signature' => Vector::TRANSFER_SIGNATURE], str_replace('12345', '12346', Vector::TRANSFER_BODY)];
        yield 'the signature in upper case' => [['x-signature' => strtoupper(Vector::TRANSFER_SIGNATURE)], Vector::TRANSFER_BODY];
        yield 'the signature of demo-card' => [['card-signature' => Vector::CARD_HEADER], Vector::TRANSFER_BODY];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('badSignatures')]
    public function testAMissingOrWrongSignatureIsInvalid(array $headers, string $body): void
    {
        $this->expectException(InvalidWebhook::class);

        $this->parse($headers, $body);
    }

    public function testTheSignatureIsCheckedBeforeTheBody(): void
    {
        try {
            $this->parse(['x-signature' => 'bad'], 'not json');
            self::fail('A body with a wrong signature was read.');
        } catch (InvalidWebhook $invalid) {
            self::assertStringContainsString('signature', $invalid->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function webhooksForAnEmptySecret(): iterable
    {
        yield 'signed with the empty key' => [['x-signature' => hash_hmac('sha256', Vector::TRANSFER_BODY, '')], Vector::TRANSFER_BODY];
        yield 'signed with the key of the shared vector' => [['x-signature' => Vector::TRANSFER_SIGNATURE], Vector::TRANSFER_BODY];
        yield 'no header' => [[], Vector::TRANSFER_BODY];
        yield 'a body that is no JSON' => [[], 'not json'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('webhooksForAnEmptySecret')]
    public function testAnEmptyWebhookSecretRefusesEveryWebhookBeforeTheSignatureIsChecked(array $headers, string $body): void
    {
        try {
            $this->parse($headers, $body, secret: '');
            self::fail('A webhook was accepted with an empty webhook secret.');
        } catch (InvalidWebhook $invalid) {
            self::assertStringContainsString('empty webhook secret', $invalid->getMessage());
            self::assertStringNotContainsString('signature', $invalid->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBodies(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'a JSON list' => ['[1]'];
        yield 'no event id' => [self::body([], without: 'eventId')];
        yield 'an event id of another format' => [self::body(['eventId' => 'evt/1'])];
        yield 'no transaction' => [self::body([], without: 'transactionId')];
        yield 'a numeric transaction' => [self::body(['transactionId' => 7])];
        yield 'an unknown status' => [self::body(['status' => 'PENDING'])];
        yield 'a status in lower case' => [self::body(['status' => 'paid'])];
        yield 'no amount' => [self::body([], without: 'amount')];
        yield 'an amount as text' => [self::body(['amount' => '12345'])];
        yield 'a fractional amount' => [self::body(['amount' => 123.45])];
        yield 'a zero amount' => [self::body(['amount' => 0])];
        yield 'a negative amount' => [self::body(['amount' => -1])];
        yield 'another currency' => [self::body(['currency' => 'EUR'])];
        yield 'no currency' => [self::body([], without: 'currency')];
        yield 'paid without paidAt' => [self::body([], without: 'paidAt')];
        yield 'paidAt that is no time' => [self::body(['paidAt' => 'yesterday'])];
        yield 'paidAt without a zone' => [self::body(['paidAt' => '2026-11-06T12:00:00'])];
        yield 'paidAt of a day that does not exist' => [self::body(['paidAt' => '2026-02-30T12:00:00Z'])];
        yield 'paidAt as Unix seconds' => [self::body(['paidAt' => Vector::EXECUTED_AT])];
    }

    #[DataProvider('badBodies')]
    public function testABodyThatBreaksTheFormatIsInvalid(string $body): void
    {
        $this->expectException(InvalidWebhook::class);

        $this->parse(self::signed($body), $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function parse(array $headers, string $body, string $secret = Vector::TRANSFER_SECRET): ProviderEvent
    {
        $adapter = new DemoTransferProvider(new ProviderCalls()->client, 'http://api', 'transfer-api-key', $secret);

        return $adapter->parseWebhook($headers, $body, new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return array<string, string>
     */
    private static function signed(string $body): array
    {
        return ['x-signature' => hash_hmac('sha256', $body, Vector::TRANSFER_SECRET)];
    }

    /**
     * The body of the vector with some fields replaced or one left out.
     *
     * @param array<string, mixed> $changes
     */
    private static function body(array $changes, ?string $without = null): string
    {
        $fields = json_decode(Vector::TRANSFER_BODY, true, flags: \JSON_THROW_ON_ERROR);
        $fields = [...$fields, ...$changes];
        if (null !== $without) {
            unset($fields[$without]);
        }

        return json_encode($fields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
