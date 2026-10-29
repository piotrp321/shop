<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messaging;

use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalProductSearchSource;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Shared\Domain\Event\DomainEventPublisher;
use App\Tests\Support\InMemory\RecordingProductIndexer;
use App\Tests\Unit\Catalog\Search\InMemoryProductSearchSource;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The route of a domain event on the in-memory transports of the test kernel: publish → outbox → events → handlers.
 * The product index and the product source are replaced by a spy and an in-memory source, so the test needs neither
 * Elasticsearch nor the database.
 */
final class MessengerRoutingTest extends KernelTestCase
{
    private const string PRODUCT_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string CATEGORY_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a01';

    private RecordingProductIndexer $indexer;

    protected function setUp(): void
    {
        $this->indexer = new RecordingProductIndexer();
        $container = self::getContainer();
        $container->set(ElasticsearchProductIndexer::class, $this->indexer);
        $container->set(DbalProductSearchSource::class, new InMemoryProductSearchSource([
            new ProductSearchSnapshot(self::PRODUCT_ID, 1, 'Buty biegowe', 'buty-biegowe', 'Lekkie', self::CATEGORY_ID, [], [[self::CATEGORY_ID]], null),
        ]));
    }

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
        self::assertSame([], $this->indexer->indexed);
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

    public function testConsumingEventsIndexesTheProductExactlyOnce(): void
    {
        $this->publisher()->publish($this->event());
        $this->consume('outbox');

        $this->consume('events');

        self::assertCount(1, $this->indexer->indexed);
        self::assertSame(self::PRODUCT_ID, $this->indexer->indexed[0]->id);
        self::assertSame([], $this->transport('outbox')->get());
        self::assertSame([], $this->transport('events')->get());
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
