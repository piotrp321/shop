<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Shared\Domain\Event\DomainEventPublisher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Boots the `test_messaging` kernel: the outbox is a Doctrine table in `shop_test` (rolled back after each test
 * by dama), `events` is a real RabbitMQ queue on the `test` vhost (not transactional, so emptied before each test).
 */
abstract class MessagingKernelTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootMessagingKernel();
        $this->drainEvents();
    }

    protected static function bootMessagingKernel(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'test_messaging']);
    }

    protected function publisher(): DomainEventPublisher
    {
        $publisher = self::getContainer()->get(DomainEventPublisher::class);
        self::assertInstanceOf(DomainEventPublisher::class, $publisher);

        return $publisher;
    }

    protected function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    protected function transport(string $name): TransportInterface
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(TransportInterface::class, $transport);

        return $transport;
    }

    protected function rows(string $queue): int
    {
        $count = $this->connection()->fetchOne('SELECT count(*) FROM messenger_messages WHERE queue_name = ?', [$queue]);
        self::assertIsInt($count);

        return $count;
    }

    protected function eventsInQueue(): int
    {
        $events = $this->transport('events');
        self::assertInstanceOf(AmqpTransport::class, $events);

        return $events->getMessageCount();
    }

    /**
     * Takes every message off the `events` queue and acks it.
     *
     * @return list<object>
     */
    protected function drainEvents(): array
    {
        $events = $this->transport('events');
        $messages = [];
        while ([] !== $envelopes = [...$events->get()]) {
            foreach ($envelopes as $envelope) {
                $messages[] = $envelope->getMessage();
                $events->ack($envelope);
            }
        }

        return $messages;
    }

    /**
     * Runs the worker on one transport until it has processed one message, or for at most $timeLimit seconds.
     */
    protected function consume(string $transport, int $timeLimit = 5): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => [$transport], '--limit' => 1, '--time-limit' => $timeLimit]);
        $tester->assertCommandIsSuccessful();
    }
}
