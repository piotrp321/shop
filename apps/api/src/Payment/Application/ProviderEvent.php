<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Domain\Amount;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;

/**
 * A webhook event of a provider about one of its payment sessions, read from a body whose signature was verified.
 * A succeeded payment always has the moment the provider executed it (P1: it decides whether the order is paid).
 */
final readonly class ProviderEvent
{
    private function __construct(
        public ProviderEventId $eventId,
        public ProviderPaymentId $providerPaymentId,
        public WebhookOutcome $outcome,
        public Amount $amount,
        public ?\DateTimeImmutable $executedAt,
    ) {
    }

    public static function succeeded(ProviderEventId $eventId, ProviderPaymentId $providerPaymentId, Amount $amount, \DateTimeImmutable $executedAt): self
    {
        return new self($eventId, $providerPaymentId, WebhookOutcome::Succeeded, $amount, $executedAt);
    }

    public static function failed(ProviderEventId $eventId, ProviderPaymentId $providerPaymentId, Amount $amount): self
    {
        return new self($eventId, $providerPaymentId, WebhookOutcome::Failed, $amount, null);
    }
}
