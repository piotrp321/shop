<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RequestFingerprint;

/**
 * A payment of the order R-100001 (123.45 zł) as the tests of Payment start it.
 */
final class PaymentExamples
{
    public const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000a1';
    public const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000d1';
    public const string KEY = 'pay-0192f0c4-0001';
    public const int TOTAL = 12_345;
    public const string CREATED = '2026-11-05 12:05:00+00:00';
    public const string SESSION = 'tx_0001';
    public const string REDIRECT = 'http://localhost:8000/payment-simulator/demo-transfer/pay/tx_0001';

    public static function started(ProviderCode $provider = ProviderCode::DemoTransfer, string $id = self::PAYMENT, string $key = self::KEY): Payment
    {
        $orderId = OrderId::fromString(self::ORDER);

        return Payment::start(
            PaymentId::fromString($id),
            $orderId,
            $provider,
            self::amount(),
            IdempotencyKey::fromString($key),
            RequestFingerprint::of($orderId, $provider),
            new \DateTimeImmutable(self::CREATED),
        );
    }

    public static function withSession(ProviderCode $provider = ProviderCode::DemoTransfer, string $id = self::PAYMENT, string $key = self::KEY, string $session = self::SESSION): Payment
    {
        $payment = self::started($provider, $id, $key);
        $payment->attachProviderSession(ProviderPaymentId::fromString($session), self::REDIRECT);

        return $payment;
    }

    public static function amount(int $minorUnits = self::TOTAL): Amount
    {
        return Amount::of($minorUnits, Currency::PLN);
    }
}
