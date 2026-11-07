<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Domain;

use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\InvalidPaymentInput;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RequestFingerprint;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentValuesTest extends TestCase
{
    public function testIdsAreUuidsInLowerCase(): void
    {
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-0000000000ab', PaymentId::fromString('0192F0C4-7C1A-7C4E-9A6E-0000000000AB')->toString());
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-0000000000ab', OrderId::fromString('0192F0C4-7C1A-7C4E-9A6E-0000000000AB')->toString());
        self::assertTrue(OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000ab')->equals(OrderId::fromString('0192F0C4-7C1A-7C4E-9A6E-0000000000AB')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notUuids(): iterable
    {
        yield 'empty' => [''];
        yield 'no hyphens' => ['0192f0c47c1a7c4e9a6e0000000000ab'];
        yield 'too short' => ['0192f0c4-7c1a-7c4e-9a6e-0000000000a'];
        yield 'with a newline' => ["0192f0c4-7c1a-7c4e-9a6e-0000000000ab\n"];
    }

    #[DataProvider('notUuids')]
    public function testAPaymentIdIsAUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaymentId::fromString($value);
    }

    #[DataProvider('notUuids')]
    public function testAnOrderIdIsAUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OrderId::fromString($value);
    }

    public function testTheTwoProvidersHaveCodesAndNames(): void
    {
        self::assertSame(ProviderCode::DemoTransfer, ProviderCode::fromString('demo-transfer'));
        self::assertSame(ProviderCode::DemoCard, ProviderCode::fromString('demo-card'));
        self::assertSame('Szybki przelew (demo)', ProviderCode::DemoTransfer->displayName());
        self::assertSame('Karta płatnicza (demo)', ProviderCode::DemoCard->displayName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownProviders(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['DEMO-CARD'];
        yield 'unknown' => ['paypal'];
    }

    #[DataProvider('unknownProviders')]
    public function testAnUnknownProviderIsInvalidInput(string $code): void
    {
        try {
            ProviderCode::fromString($code);
            self::fail('An unknown provider was accepted.');
        } catch (InvalidInput $invalid) {
            self::assertInstanceOf(InvalidPaymentInput::class, $invalid);
            self::assertSame('Nieznany sposób płatności.', $invalid->getMessage());
        }
    }

    public function testAnAmountIsPositiveMinorUnitsInZloty(): void
    {
        $amount = Amount::of(12_345, Currency::PLN);

        self::assertSame(12_345, $amount->minorUnits());
        self::assertTrue($amount->equals(Amount::of(12_345, Currency::PLN)));
        self::assertFalse($amount->equals(Amount::of(12_344, Currency::PLN)));
        self::assertSame(1, Amount::of(1, Currency::PLN)->minorUnits());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function notPositive(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('notPositive')]
    public function testAnAmountIsMoreThanZero(int $minorUnits): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Amount::of($minorUnits, Currency::PLN);
    }

    public function testIdsOfTheProviderAreOneTo100LettersDigitsUnderscoresOrHyphens(): void
    {
        $longest = str_repeat('a', 100);

        self::assertSame('tx_0192-ABC', ProviderPaymentId::fromString('tx_0192-ABC')->toString());
        self::assertSame($longest, ProviderPaymentId::fromString($longest)->toString());
        self::assertSame('evt_0192ABC-1', ProviderEventId::fromString('evt_0192ABC-1')->toString());
        self::assertSame($longest, ProviderEventId::fromString($longest)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProviderIds(): iterable
    {
        yield 'empty' => [''];
        yield '101 characters' => [str_repeat('a', 101)];
        yield 'a dot' => ['tx.1'];
        yield 'a slash' => ['tx/1'];
        yield 'a newline' => ["tx1\n"];
    }

    #[DataProvider('invalidProviderIds')]
    public function testAnotherIdOfThePaymentAtTheProviderIsInvalid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderPaymentId::fromString($value);
    }

    #[DataProvider('invalidProviderIds')]
    public function testAnotherIdOfAnEventIsInvalid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderEventId::fromString($value);
    }

    public function testAnIdempotencyKeyIsOneTo100CharactersOfTheOrderingFormat(): void
    {
        self::assertSame('pay-0192.a_b:c', IdempotencyKey::fromString('pay-0192.a_b:c')->toString());
        self::assertSame(str_repeat('k', 100), IdempotencyKey::fromString(str_repeat('k', 100))->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield '101 characters' => [str_repeat('k', 101)];
        yield 'a space' => ['pay 1'];
        yield 'a newline' => ["pay1\n"];
    }

    #[DataProvider('invalidKeys')]
    public function testAnotherIdempotencyKeyIsInvalidInput(string $value): void
    {
        $this->expectExceptionObject(new InvalidPaymentInput('Nieprawidłowy klucz żądania.'));

        IdempotencyKey::fromString($value);
    }

    public function testTheFingerprintIsSha256OfTheOrderAndTheProvider(): void
    {
        $order = OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000d1');
        $fingerprint = RequestFingerprint::of($order, ProviderCode::DemoCard);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $fingerprint->toString());
        self::assertTrue($fingerprint->equals(RequestFingerprint::of(OrderId::fromString('0192F0C4-7C1A-7C4E-9A6E-0000000000D1'), ProviderCode::DemoCard)));
        self::assertTrue($fingerprint->equals(RequestFingerprint::fromString($fingerprint->toString())));
        self::assertFalse($fingerprint->equals(RequestFingerprint::of($order, ProviderCode::DemoTransfer)));
        self::assertFalse($fingerprint->equals(RequestFingerprint::of(OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000d2'), ProviderCode::DemoCard)));
    }

    public function testAStoredFingerprintIs64HexadecimalDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RequestFingerprint::fromString(str_repeat('g', 64));
    }
}
