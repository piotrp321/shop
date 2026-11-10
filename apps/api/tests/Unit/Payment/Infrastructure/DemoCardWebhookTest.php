<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Payment\Application\InvalidWebhook;
use App\Payment\Application\ProviderEvent;
use App\Payment\Application\WebhookOutcome;
use App\Payment\Infrastructure\Provider\DemoCardProvider;
use App\Tests\Support\WebhookSignatureVector as Vector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The webhooks of demo-card: `Card-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, verified
 * (hash_equals) before the body is read, with a tolerance of 300 s between `t` and now; `checkout.completed` with
 * `completedAt` (Unix seconds) succeeds, `checkout.failed` fails, any other type is ignored; amounts as "123.45"
 * without floats. Any fault is InvalidWebhook. The shared vector (WebhookSignatureVector) is what the simulator signs.
 */
final class DemoCardWebhookTest extends TestCase
{
    public function testTheSignedEventOfTheSharedVectorIsAPaymentExecutedAtCompletedAt(): void
    {
        $event = $this->parse(['card-signature' => Vector::CARD_HEADER], Vector::CARD_BODY);

        self::assertInstanceOf(ProviderEvent::class, $event);
        self::assertSame(Vector::EVENT_ID, $event->eventId->toString());
        self::assertSame(Vector::TRANSACTION_ID, $event->providerPaymentId->toString());
        self::assertSame(WebhookOutcome::Succeeded, $event->outcome);
        self::assertSame(Vector::AMOUNT, $event->amount->minorUnits());
        self::assertEquals(new \DateTimeImmutable('@'.Vector::EXECUTED_AT), $event->executedAt);
    }

    public function testAFailedCheckoutFailsWithoutATimeOfExecution(): void
    {
        $body = self::body(['type' => 'checkout.failed'], without: 'completedAt');

        $event = $this->parse(self::signed($body), $body);

        self::assertInstanceOf(ProviderEvent::class, $event);
        self::assertSame(WebhookOutcome::Failed, $event->outcome);
        self::assertNull($event->executedAt);
    }

    public function testAnEventOfAnotherTypeIsIgnored(): void
    {
        $body = '{"id":"evt_1","type":"checkout.expired","data":{}}';

        self::assertNull($this->parse(self::signed($body), $body));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function timesWithinTheTolerance(): iterable
    {
        yield 'signed now' => [0];
        yield 'signed 300 s ago' => [300];
        yield 'signed 300 s ahead (clock skew)' => [-300];
    }

    #[DataProvider('timesWithinTheTolerance')]
    public function testASignatureWithinThreeHundredSecondsIsAccepted(int $age): void
    {
        $event = $this->parse(['card-signature' => Vector::CARD_HEADER], Vector::CARD_BODY, Vector::CARD_TIMESTAMP + $age);

        self::assertNotNull($event);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function timesOutsideTheTolerance(): iterable
    {
        yield 'signed 301 s ago' => [301];
        yield 'signed an hour ago' => [3600];
        yield 'signed 301 s ahead' => [-301];
    }

    #[DataProvider('timesOutsideTheTolerance')]
    public function testASignatureOutsideTheToleranceIsInvalidEvenIfItMatches(int $age): void
    {
        $this->expectException(InvalidWebhook::class);

        $this->parse(['card-signature' => Vector::CARD_HEADER], Vector::CARD_BODY, Vector::CARD_TIMESTAMP + $age);
    }

    public function testOneOfSeveralSignaturesMayMatch(): void
    {
        $header = 't='.Vector::CARD_TIMESTAMP.',v1='.str_repeat('0', 64).',v1='.Vector::CARD_SIGNATURE;

        self::assertNotNull($this->parse(['card-signature' => $header], Vector::CARD_BODY));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badSignatures(): iterable
    {
        yield 'no header' => [[], Vector::CARD_BODY];
        yield 'an empty header' => [['card-signature' => ''], Vector::CARD_BODY];
        yield 'no timestamp' => [['card-signature' => 'v1='.Vector::CARD_SIGNATURE], Vector::CARD_BODY];
        yield 'no signature' => [['card-signature' => 't='.Vector::CARD_TIMESTAMP], Vector::CARD_BODY];
        yield 'a timestamp that is no number' => [['card-signature' => 't=now,v1='.Vector::CARD_SIGNATURE], Vector::CARD_BODY];
        yield 'another timestamp' => [['card-signature' => 't='.(Vector::CARD_TIMESTAMP + 1).',v1='.Vector::CARD_SIGNATURE], Vector::CARD_BODY];
        yield 'another secret' => [['card-signature' => 't='.Vector::CARD_TIMESTAMP.',v1='.hash_hmac('sha256', Vector::CARD_TIMESTAMP.'.'.Vector::CARD_BODY, 'another-secret')], Vector::CARD_BODY];
        yield 'a changed body' => [['card-signature' => Vector::CARD_HEADER], str_replace('123.45', '123.46', Vector::CARD_BODY)];
        yield 'the signature of demo-transfer' => [['x-signature' => Vector::TRANSFER_SIGNATURE], Vector::CARD_BODY];
        yield 'the body alone signed (the scheme of demo-transfer)' => [['card-signature' => 't='.Vector::CARD_TIMESTAMP.',v1='.hash_hmac('sha256', Vector::CARD_BODY, Vector::CARD_SECRET)], Vector::CARD_BODY];
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
            $this->parse(['card-signature' => 't='.Vector::CARD_TIMESTAMP.',v1=bad'], 'not json');
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
        yield 'signed with the empty key' => [['card-signature' => 't='.Vector::CARD_TIMESTAMP.',v1='.hash_hmac('sha256', Vector::CARD_TIMESTAMP.'.'.Vector::CARD_BODY, '')], Vector::CARD_BODY];
        yield 'signed with the key of the shared vector' => [['card-signature' => Vector::CARD_HEADER], Vector::CARD_BODY];
        yield 'no header' => [[], Vector::CARD_BODY];
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
        yield 'no type' => [self::event(['type' => null])];
        yield 'no id' => [self::event(['id' => null])];
        yield 'an id of another format' => [self::event(['id' => 'evt 1'])];
        yield 'no data' => [self::event(['data' => null])];
        yield 'no session' => [self::body([], without: 'sessionId')];
        yield 'an amount as a number' => [self::body(['amount' => 123.45])];
        yield 'an amount with one decimal' => [self::body(['amount' => '1.5'])];
        yield 'a negative amount' => [self::body(['amount' => '-1.00'])];
        yield 'an amount with a leading zero' => [self::body(['amount' => '01.00'])];
        yield 'a zero amount' => [self::body(['amount' => '0.00'])];
        yield 'another currency' => [self::body(['currency' => 'EUR'])];
        yield 'completed without completedAt' => [self::body([], without: 'completedAt')];
        yield 'completedAt as text' => [self::body(['completedAt' => '1793966400'])];
        yield 'completedAt as RFC 3339' => [self::body(['completedAt' => '2026-11-06T12:00:00Z'])];
        yield 'completedAt of zero' => [self::body(['completedAt' => 0])];
        yield 'completedAt after the year 9999' => [self::body(['completedAt' => 253_402_300_800])];
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
    private function parse(array $headers, string $body, int $now = Vector::CARD_TIMESTAMP, string $secret = Vector::CARD_SECRET): ?ProviderEvent
    {
        $adapter = new DemoCardProvider(new ProviderCalls()->client, 'http://api', 'card-api-key', $secret);

        return $adapter->parseWebhook($headers, $body, new \DateTimeImmutable('@'.$now));
    }

    /**
     * @return array<string, string>
     */
    private static function signed(string $body): array
    {
        return ['card-signature' => 't='.Vector::CARD_TIMESTAMP.',v1='.hash_hmac('sha256', Vector::CARD_TIMESTAMP.'.'.$body, Vector::CARD_SECRET)];
    }

    /**
     * The event of the vector with fields of the top level replaced (null leaves the field out).
     *
     * @param array<string, mixed> $changes
     */
    private static function event(array $changes): string
    {
        $event = json_decode(Vector::CARD_BODY, true, flags: \JSON_THROW_ON_ERROR);
        foreach ($changes as $name => $value) {
            if (null === $value) {
                unset($event[$name]);
            } else {
                $event[$name] = $value;
            }
        }

        return json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The event of the vector with `type` or fields of `data` replaced, or one field of `data` left out.
     *
     * @param array<string, mixed> $changes
     */
    private static function body(array $changes, ?string $without = null): string
    {
        $event = json_decode(Vector::CARD_BODY, true, flags: \JSON_THROW_ON_ERROR);
        $data = $event['data'];
        foreach ($changes as $name => $value) {
            if ('type' === $name) {
                $event['type'] = $value;
            } else {
                $data[$name] = $value;
            }
        }
        if (null !== $without) {
            unset($data[$without]);
        }
        $event['data'] = $data;

        return json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
