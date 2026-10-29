<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

/**
 * The whole route on real infrastructure: CreateProduct → outbox (Postgres) → relay → RabbitMQ → IndexProduct →
 * Elasticsearch. The product goes to a temporary alias, so the shared test index stays untouched.
 */
final class EventFlowTest extends MessagingKernelTestCase
{
    use UsesTemporarySearchAlias;

    private const string CATEGORY_ID = '0198a1f0-0000-7000-8000-0000000000c1';
    private const string PRODUCT_ID = '0198a1f0-0000-7000-8000-0000000000a1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->maintenance()->setup();
        self::getContainer()->set(
            ElasticsearchProductIndexer::class,
            new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias()),
        );
    }

    public function testCreatedProductReachesTheIndexThroughTheOutboxAndRabbitMq(): void
    {
        $this->createProduct();
        self::assertSame(1, $this->rows('outbox'));

        $this->consumeOutboxAndEvents();

        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
        $document = self::esClient()->get(['index' => $this->alias(), 'id' => self::PRODUCT_ID]);
        \assert($document instanceof Elasticsearch);
        self::assertSame(1, $document['_version']);
        $source = $document['_source'];
        \assert(\is_array($source));
        self::assertSame(self::PRODUCT_ID, $source['id']);
        self::assertSame(1, $source['version']);
        self::assertSame('Buty biegowe', $source['name']);
        self::assertSame('buty-biegowe', $source['slug']);
        self::assertSame(self::CATEGORY_ID, $source['main_category_id']);
        self::assertSame([self::CATEGORY_ID], $source['category_ancestor_ids']);
    }

    public function testTheSameEventDeliveredTwiceLeavesOneDocumentAndNoFailure(): void
    {
        $this->createProduct();
        $this->consumeOutboxAndEvents();
        // At-least-once delivery: the broker hands the same event over again.
        $this->transport('events')->send(new Envelope(
            new ProductCreated(self::PRODUCT_ID, new \DateTimeImmutable()),
            [new BusNameStamp('event.bus')],
        ));

        $this->consume('events');

        // A failed handler would put the message back through the retry delay (1 s ±10 %), so wait it out.
        usleep(1_500_000);
        self::assertSame(0, $this->eventsInQueue());
        self::assertSame(0, $this->rows('failed'));
        $document = self::esClient()->get(['index' => $this->alias(), 'id' => self::PRODUCT_ID]);
        \assert($document instanceof Elasticsearch);
        self::assertSame(1, $document['_version']);
    }

    private function createProduct(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new CreateCategory(self::CATEGORY_ID, 'Obuwie', null));
        $bus->dispatch(new CreateProduct(
            self::PRODUCT_ID,
            'Buty biegowe',
            'buty-biegowe',
            'Lekkie',
            self::CATEGORY_ID,
            [],
            'standard',
            [['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'front']],
        ));
    }

    private function consumeOutboxAndEvents(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => 2, '--time-limit' => 10]);
        $tester->assertCommandIsSuccessful();
    }
}
