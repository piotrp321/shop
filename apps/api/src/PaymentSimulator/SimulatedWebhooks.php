<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * Builds the webhook of a decided transaction as the provider would send it. The event id is fixed by the
 * transaction, so a retried delivery is the same event for the receiver; the timestamp of the demo-card signature is
 * the time of the attempt.
 */
final readonly class SimulatedWebhooks
{
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public function __construct(private ProviderCredentials $credentials)
    {
    }

    public function build(Transaction $transaction, \DateTimeImmutable $now): SignedWebhook
    {
        $paid = TransactionStatus::Paid === $transaction->status;
        if (!$paid && TransactionStatus::Rejected !== $transaction->status) {
            throw new \LogicException('Only a decided transaction has a webhook.');
        }
        $secret = $this->credentials->webhookSecret($transaction->provider);
        $eventId = 'evt_'.str_replace('-', '', $transaction->id);
        $decidedAt = $transaction->decidedAt ?? throw new \LogicException('A decided transaction has a decision time.');

        if (Provider::DemoTransfer === $transaction->provider) {
            $body = self::json([
                'eventId' => $eventId,
                'transactionId' => $transaction->id,
                'merchantRef' => $transaction->reference,
                'status' => $paid ? 'PAID' : 'REJECTED',
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                ...($paid ? ['paidAt' => $decidedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')] : []),
            ]);

            return new SignedWebhook($transaction->provider->webhookPath(), $body, [
                'Content-Type' => 'application/json',
                'X-Signature' => WebhookSignature::transfer($secret, $body),
            ]);
        }

        $body = self::json([
            'id' => $eventId,
            'type' => $paid ? 'checkout.completed' : 'checkout.failed',
            'data' => [
                'sessionId' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => Amounts::format($transaction->amount),
                'currency' => $transaction->currency,
                ...($paid ? ['completedAt' => $decidedAt->getTimestamp()] : []),
            ],
        ]);

        return new SignedWebhook($transaction->provider->webhookPath(), $body, [
            'Content-Type' => 'application/json',
            'Card-Signature' => WebhookSignature::card($secret, $now->getTimestamp(), $body),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, self::JSON_FLAGS);
    }
}
