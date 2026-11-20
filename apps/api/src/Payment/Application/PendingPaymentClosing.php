<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Application\Port\Clock;
use App\Payment\Domain\Payment;

/**
 * Ends a pending payment by cancelling its session at its provider, the one place that maps the answer
 * (docs/features/async-reliability/spec.md, W6): cancelled there → cancelled here; rejected there → failed here; paid
 * there → unchanged, its webhook confirms it. A payment gets its session in the transaction that writes it, so a stored
 * pending one has one; without it there is nothing to cancel at the provider. Used by StartPayment and by
 * CloseAbandonedPayment, each under the lock of the row of the payment and only after every check that may refuse the
 * closing, because no rollback undoes the cancellation at the provider. The caller saves the payment.
 */
final readonly class PendingPaymentClosing
{
    public function __construct(
        private PaymentProviders $providers,
        private Clock $clock,
    ) {
    }

    /**
     * @return SessionCancellation what the provider answered (Cancelled without a session)
     *
     * @throws PaymentProviderUnavailable the payment is unchanged
     */
    public function close(Payment $pending): SessionCancellation
    {
        $session = $pending->providerPaymentId();
        $cancellation = null === $session ? SessionCancellation::Cancelled : $this->providers->get($pending->provider())->cancelSession($session);
        match ($cancellation) {
            SessionCancellation::Cancelled => $pending->cancel($this->clock->now()),
            SessionCancellation::AlreadyRejected => $pending->fail($this->clock->now()),
            SessionCancellation::AlreadyPaid => null,
        };

        return $cancellation;
    }
}
