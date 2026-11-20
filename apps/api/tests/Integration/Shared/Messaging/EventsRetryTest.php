<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Event\ProductCreated;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Support\OriginalImages;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

/**
 * The retries of `events` on real infrastructure (spec: docs/features/async-reliability/spec.md, W3): a consumer that
 * keeps failing is tried 11 times (the delivery and 10 retries), then the message is in `failed`; an unrecoverable
 * failure goes to `failed` at once. The test kernel shortens the delays in the proportion of the production ones.
 */
final class EventsRetryTest extends MessagingKernelTestCase
{
    use UsesTemporarySearchAlias;

    private const string CATEGORY_ID = '0198a1f0-0000-7000-8000-0000000000c1';
    private const string PRODUCT_ID = '0198a1f0-0000-7000-8000-0000000000a1';

    protected function setUp(): void
    {
        parent::setUp();
        OriginalImages::exist(self::getContainer(), 'originals/'.str_repeat('a', 64).'.jpg');
        $this->maintenance()->setup();
    }

    public function testATransientFailureIsRetriedTenTimesThenTheMessageIsInFailed(): void
    {
        $logs = $this->messengerLogs();
        $this->createProduct();
        // Nothing listens on this port: the handler fails with a transient error every time.
        self::getContainer()->set(
            ElasticsearchProductIndexer::class,
            new ElasticsearchProductIndexer((new ElasticsearchClientFactory('http://127.0.0.1:1', 1.0))->create(1.0), $this->alias()),
        );
        $this->sendProductCreated();

        $started = microtime(true);
        // The delivery and ten retries; each retry comes back to the queue after its delay.
        for ($delivery = 1; $delivery <= 11; ++$delivery) {
            $this->awaitEventsInQueue(1, 5.0);
            $this->consume('events', 10);
        }

        self::assertSame(10, $this->countRetries($logs));
        self::assertSame(1, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
        self::assertLessThan(8.0, microtime(true) - $started, 'the test of the exhausted retries takes seconds');
    }

    public function testAnUnrecoverableFailureGoesToFailedWithoutRetries(): void
    {
        $logs = $this->messengerLogs();
        // The product does not exist: IndexProduct throws UnrecoverableMessageHandlingException.
        $this->sendProductCreated();

        $this->consume('events', 10);

        self::assertSame(0, $this->countRetries($logs));
        self::assertSame(1, $this->rows('failed'));
        usleep(300_000);
        self::assertSame(0, $this->eventsInQueue(), 'no retry comes back');
    }

    private function createProduct(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(CreateCategory::bySeed(self::CATEGORY_ID, 'Obuwie', 'obuwie', null));
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

    private function sendProductCreated(): void
    {
        $this->transport('events')->send(new Envelope(
            new ProductCreated(self::PRODUCT_ID, new \DateTimeImmutable()),
            [new BusNameStamp('event.bus')],
        ));
    }

    private function messengerLogs(): TestHandler
    {
        $logger = self::getContainer()->get('monolog.logger.messenger');
        self::assertInstanceOf(Logger::class, $logger);
        $logs = new TestHandler();
        $logger->pushHandler($logs);

        return $logs;
    }

    private function countRetries(TestHandler $logs): int
    {
        $count = 0;
        foreach ($logs->getRecords() as $record) {
            if (str_contains($record->message, 'Sending for retry')) {
                ++$count;
            }
        }

        return $count;
    }
}
