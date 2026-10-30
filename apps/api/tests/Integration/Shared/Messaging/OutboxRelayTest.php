<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Domain\Product\ProductCreated;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

/**
 * The relay on the real transports: what reaches RabbitMQ, what stays in the outbox when it cannot, and why a
 * consumer may get the same event twice.
 */
final class OutboxRelayTest extends MessagingKernelTestCase
{
    private const string PRODUCT_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    private bool $brokerBroken = false;
    private mixed $rabbitMqHost = null;

    protected function tearDown(): void
    {
        $this->restoreBroker();
        parent::tearDown();
    }

    public function testRelayPublishesTheEventToRabbitMqAndDeletesTheOutboxRow(): void
    {
        $this->publisher()->publish($this->event());

        $this->consume('outbox');

        self::assertSame(0, $this->rows('outbox'));
        self::assertEquals([$this->event()], $this->drainEvents());
    }

    public function testEventStaysInTheOutboxWhileTheBrokerIsDownAndIsRelayedOnceItIsBack(): void
    {
        $this->publisher()->publish($this->event());
        $this->breakBroker();

        // Every failed attempt replaces the row with a copy delayed by the retry strategy: 1, 2, 4 … 32 s, then
        // 60 s, about 25 minutes in all, so a broker restart does not push events to `failed`. Each delay has ±10 %
        // jitter; available_at is rounded up to the second, created_at truncated.
        for ($retry = 1; $retry <= 30; ++$retry) {
            $delay = min(2 ** ($retry - 1), 60);
            $this->consume('outbox');

            self::assertSame(1, $this->rows('outbox'));
            self::assertSame($retry, $this->outboxEnvelope()->last(RedeliveryStamp::class)?->getRetryCount());
            self::assertGreaterThanOrEqual((int) floor($delay * 0.9), $this->outboxDelay());
            self::assertLessThanOrEqual(min((int) ceil($delay * 1.1), 60) + 1, $this->outboxDelay());
            $this->elapseOutboxDelay();
        }

        // The 31st failure exhausts the retries: the event moves to `failed` instead of being lost.
        $this->consume('outbox');
        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(1, $this->rows('failed'));

        $this->restoreBroker();
        $this->retryFailed();

        self::assertSame(0, $this->rows('failed'));
        self::assertEquals([$this->event()], $this->drainEvents());
    }

    public function testEventRejectedByTheBrokerStaysInTheOutbox(): void
    {
        $this->publisher()->publish($this->event());
        // A full queue that rejects publishes: the broker answers with a nack instead of storing the message.
        $this->setEventsQueuePolicy(['max-length' => 0, 'overflow' => 'reject-publish']);

        try {
            $this->consume('outbox');
        } finally {
            $this->setEventsQueuePolicy(null);
        }

        // Without publisher confirms the relay would not see the nack: it would ack the row and the event would be gone.
        self::assertSame(1, $this->rows('outbox'));
        self::assertSame(1, $this->outboxEnvelope()->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testEventTheBrokerCannotRouteStaysInTheOutbox(): void
    {
        // The first relay sets up the exchange, the queue and the binding, as a long-running worker does once.
        $this->publisher()->publish($this->event());
        $this->consume('outbox');
        self::assertCount(1, $this->drainEvents());

        // Without the queue the exchange has nowhere to route the event, yet the broker confirms the publish.
        $this->managementApi('DELETE', '/api/queues/test/events');
        try {
            $this->publisher()->publish($this->event());
            $this->consume('outbox');
        } finally {
            $events = $this->transport('events');
            self::assertInstanceOf(SetupableTransportInterface::class, $events);
            $events->setup();
        }

        self::assertSame(1, $this->rows('outbox'));
        $envelope = $this->outboxEnvelope();
        self::assertSame(1, $envelope->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('The broker could not route the message to any queue.', $envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage());
        self::assertSame(0, $this->eventsInQueue());

        // Once the queue is back, the retry delivers the event.
        $this->elapseOutboxDelay();
        $this->consume('outbox');
        self::assertSame(0, $this->rows('outbox'));
        self::assertEquals([$this->event()], $this->drainEvents());
    }

    public function testRelayThatDiesBeforeTheAckPublishesTheEventTwice(): void
    {
        $this->publisher()->publish($this->event());

        // A worker takes the row and relays it, then dies before the ack (DELETE of the row).
        $outbox = $this->transport('outbox');
        $envelopes = [...$outbox->get()];
        self::assertCount(1, $envelopes);
        $this->eventBus()->dispatch($envelopes[0]->with(new ReceivedStamp('outbox')));
        self::assertSame(1, $this->eventsInQueue());

        // The row is marked as delivered, so another worker skips it until redeliver_timeout (300 s) passes.
        $this->consume('outbox', timeLimit: 1);
        self::assertSame(1, $this->rows('outbox'));
        self::assertSame(1, $this->eventsInQueue());

        $this->connection()->executeStatement(
            "UPDATE messenger_messages SET delivered_at = delivered_at - interval '301 seconds' WHERE queue_name = 'outbox'",
        );
        $this->consume('outbox');

        self::assertSame(0, $this->rows('outbox'));
        self::assertEquals([$this->event(), $this->event()], $this->drainEvents());
    }

    private function event(): ProductCreated
    {
        return new ProductCreated(self::PRODUCT_ID, new \DateTimeImmutable('2026-10-29T12:00:00+00:00'));
    }

    private function eventBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('event.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * The one outbox message, found by id: all() lists only messages available now, not a delayed retry.
     */
    private function outboxEnvelope(): Envelope
    {
        $outbox = $this->transport('outbox');
        self::assertInstanceOf(ListableReceiverInterface::class, $outbox);
        $envelope = $outbox->find($this->connection()->fetchOne("SELECT id FROM messenger_messages WHERE queue_name = 'outbox'"));
        self::assertNotNull($envelope);
        self::assertNotNull($envelope->last(ErrorDetailsStamp::class));

        return $envelope;
    }

    private function outboxDelay(): int
    {
        $delay = $this->connection()->fetchOne(
            "SELECT extract(epoch FROM available_at - created_at)::int FROM messenger_messages WHERE queue_name = 'outbox'",
        );
        self::assertIsInt($delay);

        return $delay;
    }

    private function elapseOutboxDelay(): void
    {
        $this->connection()->executeStatement(
            "UPDATE messenger_messages SET available_at = created_at WHERE queue_name = 'outbox'",
        );
    }

    /**
     * Reboots the kernel with RabbitMQ at an address where nothing listens.
     */
    private function breakBroker(): void
    {
        $this->rabbitMqHost = $_SERVER['RABBITMQ_HOST'] ?? null;
        $this->brokerBroken = true;
        $_SERVER['RABBITMQ_HOST'] = $_ENV['RABBITMQ_HOST'] = '127.0.0.1';
        self::bootMessagingKernel();
    }

    private function restoreBroker(): void
    {
        if (!$this->brokerBroken) {
            return;
        }
        if (null === $this->rabbitMqHost) {
            unset($_SERVER['RABBITMQ_HOST'], $_ENV['RABBITMQ_HOST']);
        } else {
            $_SERVER['RABBITMQ_HOST'] = $_ENV['RABBITMQ_HOST'] = $this->rabbitMqHost;
        }
        $this->brokerBroken = false;
        self::bootMessagingKernel();
    }

    /**
     * Sets (or with null removes) a policy on the `events` queue of the `test` vhost through the Management API,
     * and waits until the queue applies it.
     *
     * @param array<string, int|string>|null $definition
     */
    private function setEventsQueuePolicy(?array $definition): void
    {
        $this->managementApi(null === $definition ? 'DELETE' : 'PUT', '/api/policies/test/reject-events', null === $definition ? null : [
            'pattern' => '^events$',
            'apply-to' => 'queues',
            'definition' => $definition,
        ]);

        $deadline = microtime(true) + 5;
        do {
            // Without disable_stats the policy shows up only with the next statistics refresh, seconds later.
            $queue = json_decode($this->managementApi('GET', '/api/queues/test/events?disable_stats=true'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($queue);
            $applied = null === $definition ? !isset($queue['policy']) : 'reject-events' === ($queue['policy'] ?? null);
            if ($applied) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The queue policy was not applied in 5 s.');
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function managementApi(string $method, string $path, ?array $body = null): string
    {
        $host = $_SERVER['RABBITMQ_HOST'] ?? null;
        $user = $_SERVER['RABBITMQ_USER'] ?? null;
        $password = $_SERVER['RABBITMQ_DEFAULT_PASS'] ?? null;
        self::assertIsString($host);
        self::assertIsString($user);
        self::assertIsString($password);

        $response = file_get_contents('http://'.$host.':15672'.$path, false, stream_context_create(['http' => [
            'method' => $method,
            'header' => ['Authorization: Basic '.base64_encode($user.':'.$password), 'Content-Type: application/json'],
            'content' => null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
            'timeout' => 5,
        ]]));
        self::assertIsString($response, $method.' '.$path.' failed.');

        return $response;
    }

    private function retryFailed(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:failed:retry'));
        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();
    }
}
