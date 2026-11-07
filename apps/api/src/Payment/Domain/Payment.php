<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * One attempt to pay an order at one provider. It starts pending, gets the session of the provider once (in the
 * transaction that starts it) and ends succeeded, failed or cancelled. No version: the webhook and the cancellation
 * at a new attempt change an existing payment only under the lock of its row.
 *
 * Money weighs more than an earlier refusal or cancellation: a failed or cancelled payment the provider reports as
 * paid becomes succeeded. A succeeded payment that cannot pay its order keeps the reason of its refund (by hand).
 */
final class Payment
{
    public const int MAX_REDIRECT_URL_LENGTH = 500;

    private function __construct(
        private readonly PaymentId $id,
        private readonly OrderId $orderId,
        private readonly ProviderCode $provider,
        private readonly Amount $amount,
        private PaymentStatus $status,
        private ?ProviderPaymentId $providerPaymentId,
        private ?string $redirectUrl,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly RequestFingerprint $requestFingerprint,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $completedAt,
        private ?\DateTimeImmutable $executedAt,
        private ?RefundReason $refundRequiredReason,
    ) {
    }

    public static function start(PaymentId $id, OrderId $orderId, ProviderCode $provider, Amount $amount, IdempotencyKey $key, RequestFingerprint $fingerprint, \DateTimeImmutable $now): self
    {
        return new self($id, $orderId, $provider, $amount, PaymentStatus::Pending, null, null, $key, $fingerprint, $now, null, null, null);
    }

    /**
     * Rebuilds a stored payment as it is; checks nothing (the database keeps the same rules in CHECK constraints).
     */
    public static function reconstitute(
        PaymentId $id,
        OrderId $orderId,
        ProviderCode $provider,
        Amount $amount,
        PaymentStatus $status,
        ?ProviderPaymentId $providerPaymentId,
        ?string $redirectUrl,
        IdempotencyKey $idempotencyKey,
        RequestFingerprint $requestFingerprint,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $completedAt,
        ?\DateTimeImmutable $executedAt,
        ?RefundReason $refundRequiredReason,
    ): self {
        return new self($id, $orderId, $provider, $amount, $status, $providerPaymentId, $redirectUrl, $idempotencyKey, $requestFingerprint, $createdAt, $completedAt, $executedAt, $refundRequiredReason);
    }

    /**
     * The session the provider opened for this payment; the same session again changes nothing.
     *
     * @throws InvalidPayment another session, or an address that is empty or longer than 500 characters
     */
    public function attachProviderSession(ProviderPaymentId $providerPaymentId, string $redirectUrl): void
    {
        if ('' === $redirectUrl || \strlen($redirectUrl) > self::MAX_REDIRECT_URL_LENGTH) {
            throw new InvalidPayment('The redirect address of a payment is 1 to 500 characters.');
        }
        if (null !== $this->providerPaymentId) {
            if (!$this->providerPaymentId->equals($providerPaymentId) || $this->redirectUrl !== $redirectUrl) {
                throw new InvalidPayment('A payment has one session of its provider.');
            }

            return;
        }
        $this->providerPaymentId = $providerPaymentId;
        $this->redirectUrl = $redirectUrl;
    }

    /**
     * The provider reports the payment executed at `executedAt`.
     *
     * @return bool whether the status changed (false: it had succeeded before)
     *
     * @throws PaymentAmountMismatch
     */
    public function succeed(Amount $amount, \DateTimeImmutable $executedAt, \DateTimeImmutable $now): bool
    {
        if (!$amount->equals($this->amount)) {
            throw new PaymentAmountMismatch();
        }
        if (PaymentStatus::Succeeded === $this->status) {
            return false;
        }
        $this->status = PaymentStatus::Succeeded;
        $this->executedAt = $executedAt;
        $this->completedAt = $now;

        return true;
    }

    /**
     * The provider refused the payment; ends only a pending one.
     */
    public function fail(\DateTimeImmutable $now): void
    {
        $this->end(PaymentStatus::Failed, $now);
    }

    /**
     * The session was cancelled at the provider; ends only a pending one.
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        $this->end(PaymentStatus::Cancelled, $now);
    }

    /**
     * Marks a succeeded payment for a refund by hand; the first reason stays.
     *
     * @throws InvalidPayment the payment did not succeed
     */
    public function requireRefund(RefundReason $reason): void
    {
        if (PaymentStatus::Succeeded !== $this->status) {
            throw new InvalidPayment('Only a succeeded payment is refunded.');
        }
        $this->refundRequiredReason ??= $reason;
    }

    public function isReplayOf(RequestFingerprint $fingerprint): bool
    {
        return $this->requestFingerprint->equals($fingerprint);
    }

    /**
     * Where the customer goes back to this payment: the page of the provider, while the payment is pending and its
     * order still payable (before the deadline).
     */
    public function resumeUrl(bool $orderPayable): ?string
    {
        return PaymentStatus::Pending === $this->status && $orderPayable ? $this->redirectUrl : null;
    }

    public function id(): PaymentId
    {
        return $this->id;
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function provider(): ProviderCode
    {
        return $this->provider;
    }

    public function amount(): Amount
    {
        return $this->amount;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function providerPaymentId(): ?ProviderPaymentId
    {
        return $this->providerPaymentId;
    }

    public function redirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function requestFingerprint(): RequestFingerprint
    {
        return $this->requestFingerprint;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function executedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function refundRequiredReason(): ?RefundReason
    {
        return $this->refundRequiredReason;
    }

    private function end(PaymentStatus $status, \DateTimeImmutable $now): void
    {
        if (PaymentStatus::Pending !== $this->status) {
            return;
        }
        $this->status = $status;
        $this->completedAt = $now;
    }
}
