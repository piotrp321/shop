<?php

declare(strict_types=1);

namespace App\Payment\Application\View;

use App\Payment\Domain\Payment;

/**
 * A payment as the store sees it after a start: where to send the customer (null only before the provider answered,
 * which a response never shows).
 */
final readonly class PaymentView
{
    public function __construct(
        public string $paymentId,
        public string $provider,
        public string $status,
        public ?string $redirectUrl,
    ) {
    }

    public static function of(Payment $payment): self
    {
        return new self($payment->id()->toString(), $payment->provider()->value, $payment->status()->value, $payment->redirectUrl());
    }
}
