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

/**
 * The relay on the real transports: what reaches RabbitMQ, what stays in the outbox when it cannot, and why a
 * consumer may get the same event twice.
 */
final class OutboxRelayTest extends MessagingKernelTestCase
{
    private const string PRODUCT_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    private ?string $rabbitMqHost = null;

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

        // Every failed attempt replaces the row with a copy delayed by the retry strategy: 1 s, 3 s, 9 s, each
        // ±10 % jitter and rounded up to the second precision of available_at.
        foreach ([1 => 1, 2 => 3, 3 => 9] as $retry => $delay) {
            $this->consume('outbox');

            self::assertSame(1, $this->rows('outbox'));
            self::assertSame($retry, $this->outboxEnvelope()->last(RedeliveryStamp::class)?->getRetryCount());
            self::assertGreaterThanOrEqual((int) floor($delay * 0.9), $this->outboxDelay());
            self::assertLessThanOrEqual((int) ceil($delay * 1.1) + 1, $this->outboxDelay());
            $this->elapseOutboxDelay();
        }

        // The fourth failure exhausts the retries: the event moves to `failed` instead of being lost.
        $this->consume('outbox');
        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(1, $this->rows('failed'));

        $this->restoreBroker();
        $this->retryFailed();

        self::assertSame(0, $this->rows('failed'));
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

        // The row is marked as delivered, so another worker skips it until redeliver_timeout (3600 s) passes.
        $this->consume('outbox', timeLimit: 1);
        self::assertSame(1, $this->rows('outbox'));
        self::assertSame(1, $this->eventsInQueue());

        $this->connection()->executeStatement(
            "UPDATE messenger_messages SET delivered_at = delivered_at - interval '3601 seconds' WHERE queue_name = 'outbox'",
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
        $this->rabbitMqHost = \is_string($_SERVER['RABBITMQ_HOST'] ?? null) ? $_SERVER['RABBITMQ_HOST'] : null;
        $_SERVER['RABBITMQ_HOST'] = $_ENV['RABBITMQ_HOST'] = '127.0.0.1';
        self::bootMessagingKernel();
    }

    private function restoreBroker(): void
    {
        if (null === $this->rabbitMqHost) {
            return;
        }
        $_SERVER['RABBITMQ_HOST'] = $_ENV['RABBITMQ_HOST'] = $this->rabbitMqHost;
        $this->rabbitMqHost = null;
        self::bootMessagingKernel();
    }

    private function retryFailed(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:failed:retry'));
        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();
    }
}
