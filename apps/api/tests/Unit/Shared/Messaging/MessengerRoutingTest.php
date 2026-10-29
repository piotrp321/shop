<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messaging;

use App\Catalog\Domain\Product\ProductCreated;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The route of a domain event on the in-memory transports of the test kernel: publish → outbox → events.
 */
final class MessengerRoutingTest extends KernelTestCase
{
    private const string PRODUCT_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testPublishedEventIsStoredOnlyInTheOutbox(): void
    {
        $this->publisher()->publish($this->event());

        self::assertCount(1, $this->transport('outbox')->getSent());
        self::assertCount(0, $this->transport('events')->getSent());
    }

    public function testConsumingTheOutboxRelaysTheEventToEventsOnce(): void
    {
        $this->publisher()->publish($this->event());

        $this->consume('outbox');

        $sent = $this->transport('events')->getSent();
        self::assertCount(1, $sent);
        self::assertEquals($this->event(), $sent[0]->getMessage());
        self::assertCount(1, $this->transport('outbox')->getAcknowledged());
        self::assertSame([], $this->transport('outbox')->get());
    }

    public function testConsumingEventsDoesNotRelayAgain(): void
    {
        $this->publisher()->publish($this->event());
        $this->consume('outbox');

        $this->consume('events');

        self::assertCount(1, $this->transport('events')->getAcknowledged());
        self::assertCount(1, $this->transport('outbox')->getSent());
        self::assertCount(1, $this->transport('events')->getSent());
        self::assertCount(0, $this->transport('failed')->getSent());
    }

    private function event(): ProductCreated
    {
        return new ProductCreated(self::PRODUCT_ID, new \DateTimeImmutable('2026-10-29T12:00:00+00:00'));
    }

    private function publisher(): DomainEventPublisher
    {
        $publisher = self::getContainer()->get(DomainEventPublisher::class);
        self::assertInstanceOf(DomainEventPublisher::class, $publisher);

        return $publisher;
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function consume(string $transport): void
    {
        $command = (new Application(self::$kernel ?? self::bootKernel()))->find('messenger:consume');
        $tester = new CommandTester($command);
        // The worker resets services after a message, which empties the in-memory transports. A bare --no-reset
        // (null) turns that off; a value is read as "reset every N messages".
        $tester->execute(['receivers' => [$transport], '--limit' => 1, '--time-limit' => 5, '--no-reset' => null]);
        $tester->assertCommandIsSuccessful();
    }
}
