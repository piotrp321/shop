<?php

declare(strict_types=1);

namespace App\Tests\Unit\PaymentSimulator;

use App\PaymentSimulator\ProviderCredentials;
use App\PaymentSimulator\SimulatedWebhooks;
use App\PaymentSimulator\Transaction;
use App\Tests\Support\WebhookSignatureVector as Vector;
use PHPUnit\Framework\TestCase;

/**
 * The webhooks the simulator sends, against the shared vector of signatures (the adapters of Payment verify the same
 * bytes) and the bodies of the specification.
 */
final class SimulatedWebhooksTest extends TestCase
{
    private SimulatedWebhooks $webhooks;

    protected function setUp(): void
    {
        $this->webhooks = new SimulatedWebhooks(new ProviderCredentials('transfer-key', Vector::TRANSFER_SECRET, 'card-key', Vector::CARD_SECRET));
    }

    public function testATransferWebhookOfAPaidTransactionIsTheVectorBodySignedWithItsSecret(): void
    {
        $webhook = $this->webhooks->build(self::paid(self::transfer()), self::at(Vector::EXECUTED_AT + 1));

        self::assertSame('/api/v1/payments/webhooks/demo-transfer', $webhook->path);
        self::assertSame(Vector::TRANSFER_BODY, $webhook->body);
        self::assertSame(
            ['Content-Type' => 'application/json', 'X-Signature' => Vector::TRANSFER_SIGNATURE],
            $webhook->headers,
        );
    }

    public function testACardWebhookOfAPaidTransactionIsTheVectorBodySignedWithTheTimeOfSigning(): void
    {
        $webhook = $this->webhooks->build(self::paid(self::card()), self::at(Vector::CARD_TIMESTAMP));

        self::assertSame('/api/v1/payments/webhooks/demo-card', $webhook->path);
        self::assertSame(Vector::CARD_BODY, $webhook->body);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Card-Signature' => Vector::CARD_HEADER],
            $webhook->headers,
        );
    }

    public function testEveryAttemptOfACardWebhookSignsWithAFreshTimestampAndTheBodyAndEventIdStayTheSame(): void
    {
        $transaction = self::paid(self::card());

        $first = $this->webhooks->build($transaction, self::at(Vector::CARD_TIMESTAMP));
        $retry = $this->webhooks->build($transaction, self::at(Vector::CARD_TIMESTAMP + 90));

        self::assertSame($first->body, $retry->body, 'the event id is the same, so the retry is deduplicated');
        self::assertNotSame($first->headers['Card-Signature'], $retry->headers['Card-Signature']);
        self::assertStringStartsWith('t='.(Vector::CARD_TIMESTAMP + 90).',v1=', $retry->headers['Card-Signature']);
    }

    public function testARejectedTransferWebhookHasNoPaidAt(): void
    {
        $webhook = $this->webhooks->build(self::decided(self::transfer(), false), self::at(Vector::EXECUTED_AT + 1));

        self::assertSame([
            'eventId' => Vector::EVENT_ID,
            'transactionId' => Vector::TRANSACTION_ID,
            'merchantRef' => Vector::REFERENCE,
            'status' => 'REJECTED',
            'amount' => Vector::AMOUNT,
            'currency' => 'PLN',
        ], json_decode($webhook->body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testARejectedCardWebhookIsACheckoutFailedWithoutCompletedAt(): void
    {
        $webhook = $this->webhooks->build(self::decided(self::card(), false), self::at(Vector::CARD_TIMESTAMP));

        self::assertSame([
            'id' => Vector::EVENT_ID,
            'type' => 'checkout.failed',
            'data' => [
                'sessionId' => Vector::TRANSACTION_ID,
                'reference' => Vector::REFERENCE,
                'amount' => '123.45',
                'currency' => 'PLN',
            ],
        ], json_decode($webhook->body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testATransactionWithoutADecisionHasNoWebhook(): void
    {
        $this->expectException(\LogicException::class);

        $this->webhooks->build(self::transfer(), self::at(Vector::EXECUTED_AT));
    }

    public function testACancelledTransactionHasNoWebhook(): void
    {
        $transaction = self::card();
        $transaction->cancel();

        $this->expectException(\LogicException::class);

        $this->webhooks->build($transaction, self::at(Vector::EXECUTED_AT));
    }

    private static function paid(Transaction $transaction): Transaction
    {
        return self::decided($transaction, true);
    }

    private static function decided(Transaction $transaction, bool $pay): Transaction
    {
        $transaction->decide($pay, self::at(Vector::EXECUTED_AT));

        return $transaction;
    }

    private static function transfer(): Transaction
    {
        return Transaction::openTransfer(Vector::TRANSACTION_ID, Vector::REFERENCE, Vector::AMOUNT, 'PLN', 'https://shop.test/return', self::at(Vector::EXECUTED_AT + 1_800), self::at(Vector::EXECUTED_AT - 60));
    }

    private static function card(): Transaction
    {
        return Transaction::openCard(Vector::TRANSACTION_ID, Vector::REFERENCE, Vector::AMOUNT, 'PLN', 'https://shop.test/success', 'https://shop.test/cancel', self::at(Vector::EXECUTED_AT + 1_800), self::at(Vector::EXECUTED_AT - 60));
    }

    private static function at(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$timestamp);
    }
}
