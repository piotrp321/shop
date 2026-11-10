<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\CreateProductHandler;
use App\Catalog\Application\EventHandler\IndexProduct;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Shared\Infrastructure\Messaging\RelayDomainEventToBroker;
use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Support\OriginalImages;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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
        OriginalImages::exist(self::getContainer(), 'originals/'.str_repeat('a', 64).'.jpg');
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
        $source = $document['_source'];
        \assert(\is_array($source));
        self::assertSame(self::PRODUCT_ID, $source['id']);
        // The version is the time the read started, in microseconds.
        self::assertSame($document['_version'], $source['version']);
        self::assertGreaterThan(1_700_000_000_000_000, $source['version']);
        self::assertSame('Buty biegowe', $source['name']);
        self::assertSame('buty-biegowe', $source['slug']);
        self::assertSame(self::CATEGORY_ID, $source['main_category_id']);
        self::assertSame([self::CATEGORY_ID], $source['category_ancestor_ids']);
    }

    public function testTheSameEventDeliveredTwiceLeavesOneDocumentAndNoFailure(): void
    {
        $this->createProduct();
        $this->consumeOutboxAndEvents();
        $first = self::esClient()->get(['index' => $this->alias(), 'id' => self::PRODUCT_ID]);
        \assert($first instanceof Elasticsearch);
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
        // The second handling read the product again, later, and wrote it again.
        $document = self::esClient()->get(['index' => $this->alias(), 'id' => self::PRODUCT_ID]);
        \assert($document instanceof Elasticsearch);
        self::assertGreaterThan($first['_version'], $document['_version']);
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
        $count = self::esClient()->count(['index' => $this->alias()]);
        \assert($count instanceof Elasticsearch);
        self::assertSame(1, $count['count']);
    }

    /**
     * Spec: docs/features/hardening/spec.md, "Correlation ID". The request sets the identifier (the subscriber of
     * kernel.request does it before the controller runs); the worker is another process, so it starts without one and
     * takes it from the stamp that travelled in the outbox row and in the AMQP headers.
     */
    public function testTheRequestTheOutboxMessageAndTheConsumerLogUnderOneCorrelationId(): void
    {
        $correlationId = self::getContainer()->get(CorrelationId::class);
        self::assertInstanceOf(CorrelationId::class, $correlationId);
        $logger = self::getContainer()->get('monolog.logger.messenger');
        self::assertInstanceOf(Logger::class, $logger);
        $logs = new TestHandler();
        $logger->pushHandler($logs);

        $requestId = $correlationId->start();
        $this->createProduct();
        $correlationId->reset();
        $this->consumeOutboxAndEvents();

        self::assertSame(0, $this->rows('failed'));
        $handled = [];
        foreach ($logs->getRecords() as $record) {
            $handler = $record->context['handler'] ?? null;
            if (str_starts_with($record->message, 'Message ') && str_contains($record->message, ' handled by ') && \is_string($handler)) {
                $handled[$handler] = $record->extra['correlation_id'] ?? null;
            }
        }
        self::assertSame(
            [
                'the request' => $requestId,
                'the outbox message' => $requestId,
                'the consumer of the event' => $requestId,
            ],
            [
                'the request' => $handled[CreateProductHandler::class.'::__invoke'] ?? null,
                'the outbox message' => $handled[RelayDomainEventToBroker::class.'::__invoke'] ?? null,
                'the consumer of the event' => $handled[IndexProduct::class.'::__invoke'] ?? null,
            ],
            'the correlation_id of the log entries; handled: '.implode(', ', array_keys($handled)),
        );
        self::assertNull($correlationId->current(), 'the worker is left without an identifier');
    }

    private function createProduct(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new CreateCategory(self::CATEGORY_ID, 'Obuwie', 'obuwie', null));
        $bus->dispatch(new CreateProduct(
            self::PRODUCT_ID,
            'Buty biegowe',
            'buty-biegowe',
            'Lekkie',
            self::CATEGORY_ID,
            [],
            'standard',
            [['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'front']],
            [],
            [['id' => '20000000-0000-4000-8000-000000000001', 'values' => []]],
            ProductStatus::Published,
            null,
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
