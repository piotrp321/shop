<?php

declare(strict_types=1);

namespace App\Tests\Integration\PaymentSimulator;

use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\Provider;
use App\PaymentSimulator\ProviderCredentials;
use App\PaymentSimulator\SimulatedWebhooks;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Support\SimulatorTransactions;
use App\Tests\Support\WebhookSignatureVector as Vector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * The delivery of the webhooks through the Doctrine transport `payment_simulator` (the `test_messaging` kernel, rolled
 * back after each test) to the shop, which is a client of constant responses here (the forwarding client is tested
 * by ForwardingHttpClientTest): the signature as the shared vector fixes it, the end of the delivery on 2xx and 4xx,
 * the retries on 5xx and a network error with the same event and a fresh timestamp.
 */
final class DeliveryTest extends MessagingKernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $entityManager;
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];
    /** @var list<MockResponse> answers in order; the last one repeats */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        self::getContainer()->set('app.payment_http_client', new MockHttpClient($this->answer(...)));
        self::getContainer()->set(SimulatedWebhooks::class, new SimulatedWebhooks(new ProviderCredentials('transfer-key', Vector::TRANSFER_SECRET, 'card-key', Vector::CARD_SECRET)));
        $this->answers = [new MockResponse('', ['http_code' => 204])];
    }

    public function testATransferWebhookIsPostedToTheShopSignedAsTheSharedVectorFixes(): void
    {
        $this->decided(Provider::DemoTransfer, Vector::EXECUTED_AT);

        $this->consume('payment_simulator');

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame($this->webhookBase().'/api/v1/payments/webhooks/demo-transfer', $this->requests[0]['url']);
        self::assertSame(Vector::TRANSFER_BODY, $this->requests[0]['body']);
        self::assertSame(Vector::TRANSFER_SIGNATURE, $this->requests[0]['headers']['x-signature']);
        self::assertSame('application/json', $this->requests[0]['headers']['content-type']);
        self::assertSame(0, $this->rows('payment_simulator'));
    }

    public function testACardWebhookIsSignedWithTheTimeOfTheAttempt(): void
    {
        $this->decided(Provider::DemoCard, Vector::EXECUTED_AT, Vector::CARD_TIMESTAMP);

        $this->consume('payment_simulator');

        self::assertCount(1, $this->requests);
        self::assertSame($this->webhookBase().'/api/v1/payments/webhooks/demo-card', $this->requests[0]['url']);
        self::assertSame(Vector::CARD_BODY, $this->requests[0]['body']);
        self::assertSame(Vector::CARD_HEADER, $this->requests[0]['headers']['card-signature']);
        self::assertSame(0, $this->rows('payment_simulator'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function endingStatuses(): iterable
    {
        yield '200' => [200];
        yield '202' => [202];
        yield '204' => [204];
        yield '400 (bad signature or body)' => [400];
        yield '401' => [401];
        yield '404 (unknown payment)' => [404];
        yield '422 (amount mismatch)' => [422];
    }

    #[DataProvider('endingStatuses')]
    public function testA2xxOr4xxAnswerEndsTheDeliveryWithoutARetry(int $status): void
    {
        $this->answers = [new MockResponse('', ['http_code' => $status])];
        $this->decided(Provider::DemoTransfer, Vector::EXECUTED_AT);

        $this->consume('payment_simulator');

        self::assertCount(1, $this->requests);
        self::assertSame(0, $this->rows('payment_simulator'), 'the message was acknowledged');
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function failingAnswers(): iterable
    {
        yield '500' => [new MockResponse('', ['http_code' => 500])];
        yield '503 (the order is busy)' => [new MockResponse('', ['http_code' => 503])];
        yield 'a network error' => [new MockResponse('', ['error' => 'Connection refused'])];
    }

    #[DataProvider('failingAnswers')]
    public function testA5xxOrANetworkErrorIsRetriedWithTheSameEventAndAFreshTimestampUntilTheShopAnswers(MockResponse $failure): void
    {
        $this->answers = [$failure, new MockResponse('', ['http_code' => 204])];
        $this->decided(Provider::DemoCard, Vector::EXECUTED_AT, Vector::CARD_TIMESTAMP);

        $this->consume('payment_simulator');

        self::assertCount(1, $this->requests);
        self::assertSame(1, $this->rows('payment_simulator'), 'the failed delivery is stored again for the retry');
        self::assertSame(1, $this->queuedEnvelope()->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame(0, $this->rows('failed'));

        $this->elapseRetryDelay();
        self::mockTime('@'.(Vector::CARD_TIMESTAMP + 60));
        $this->consume('payment_simulator');

        self::assertCount(2, $this->requests);
        self::assertSame($this->requests[0]['body'], $this->requests[1]['body'], 'the same event id, so the shop deduplicates');
        self::assertSame(Vector::CARD_HEADER, $this->requests[0]['headers']['card-signature']);
        self::assertStringStartsWith('t='.(Vector::CARD_TIMESTAMP + 60).',v1=', $this->requests[1]['headers']['card-signature']);
        self::assertSame(0, $this->rows('payment_simulator'), 'the second attempt ended the delivery');
        self::assertSame(0, $this->rows('failed'));
    }

    public function testADeliveryThatKeepsFailingGoesToFailedAfterFiveRetries(): void
    {
        $this->answers = [new MockResponse('', ['http_code' => 500])];
        $this->decided(Provider::DemoTransfer, Vector::EXECUTED_AT);

        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->consume('payment_simulator');
            self::assertCount($attempt, $this->requests);
            if ($attempt < 6) {
                self::assertSame($attempt, $this->queuedEnvelope()->last(RedeliveryStamp::class)?->getRetryCount());
                $this->elapseRetryDelay();
            }
        }

        self::assertSame(0, $this->rows('payment_simulator'));
        self::assertSame(1, $this->rows('failed'));
    }

    public function testATransactionThatIsGoneOrWasNeverDecidedIsAcknowledgedWithoutARequest(): void
    {
        $gone = $this->decided(Provider::DemoTransfer, Vector::EXECUTED_AT);
        $this->connection()->executeStatement('DELETE FROM payment_simulator_transaction WHERE id = ?', [$gone]);
        $this->entityManager->clear();

        $this->consume('payment_simulator');

        self::assertSame([], $this->requests);
        self::assertSame(0, $this->rows('payment_simulator'));
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * Pays a new transaction of the provider at $executedAt, then puts the clock at $deliveryTime (default: the same).
     */
    private function decided(Provider $provider, int $executedAt, ?int $deliveryTime = null): string
    {
        self::mockTime('@'.$executedAt);
        $transaction = Provider::DemoTransfer === $provider
            ? SimulatorTransactions::transfer($this->entityManager, Vector::REFERENCE, Vector::AMOUNT, '2030-01-01 00:00:00 UTC', Vector::TRANSACTION_ID)
            : SimulatorTransactions::card($this->entityManager, Vector::REFERENCE, Vector::AMOUNT, '2030-01-01 00:00:00 UTC', Vector::TRANSACTION_ID);
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new DecideTransaction($transaction->id, $provider, true));
        self::assertSame(1, $this->rows('payment_simulator'));
        self::mockTime('@'.($deliveryTime ?? $executedAt));

        return $transaction->id;
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $headers = [];
        /** @var array<string, list<string>> $normalized */
        $normalized = $options['normalized_headers'];
        foreach ($normalized as $name => $lines) {
            $headers[$name] = substr($lines[0], \strlen($name) + 2);
        }
        $body = $options['body'];
        \assert(\is_string($body));
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $index = min(\count($this->requests), \count($this->answers)) - 1;

        return $this->answers[$index];
    }

    private function webhookBase(): string
    {
        return rtrim((string) getenv('PAYMENT_WEBHOOK_BASE_URL'), '/');
    }

    private function elapseRetryDelay(): void
    {
        $this->connection()->executeStatement("UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'payment_simulator'");
    }

    /**
     * The one stored message, made available first: the listing shows only what a worker could take now.
     */
    private function queuedEnvelope(): Envelope
    {
        $this->elapseRetryDelay();
        $transport = $this->transport('payment_simulator');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);
        $envelopes = [...$transport->all()];
        self::assertCount(1, $envelopes);

        return $envelopes[0];
    }
}
