<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Events;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\EditProduct;
use App\Catalog\Application\Command\PublishProduct;
use App\Catalog\Application\Command\UnpublishProduct;
use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\VariantInUse;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The product commands on the real command bus of the `test_messaging` kernel: each write that raises the version
 * leaves the integration ProductChanged in the outbox (Postgres) in its transaction, and the relay sends it to RabbitMQ
 * as the same contract. A request that changes nothing or is refused leaves nothing.
 */
final class ProductChangeOutboxTest extends MessagingKernelTestCase
{
    private const string CATEGORY_ID = '0192f0c4-0000-7000-8000-00000000c001';
    private const string PRODUCT_ID = '0192f0c4-0000-7000-8000-00000000a001';
    private const string BLACK = '0192f0c4-0000-7000-8000-00000000b001';
    private const string WHITE = '0192f0c4-0000-7000-8000-00000000b002';
    private const string ACTOR = 'staff-sub-1';
    private const string IMAGE = 'originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        $this->commandBus()->dispatch(new CreateCategory(self::CATEGORY_ID, 'Obuwie', 'obuwie', null));
        $this->commandBus()->dispatch(new CreateProduct(
            self::PRODUCT_ID,
            'Buty biegowe',
            '',
            '',
            self::CATEGORY_ID,
            [],
            'standard',
            [['key' => self::IMAGE, 'alt' => 'front']],
            ['Kolor'],
            [['id' => self::BLACK, 'values' => ['czarne']], ['id' => self::WHITE, 'values' => ['białe']]],
            ProductStatus::Draft,
            self::ACTOR,
        ));
    }

    public function testCreationLeavesOnlyProductCreatedAndADraftAtVersionOne(): void
    {
        $events = $this->outboxEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(ProductCreated::class, $events[0]);
        self::assertSame(['draft', 1, self::ACTOR, 'buty-biegowe'], $this->row());
    }

    public function testEditPublishAndUnpublishEachRaiseTheVersionAndLeaveTheIntegrationProductChanged(): void
    {
        $this->outboxEvents();

        // The slug is empty: a draft never published gets the slug of its new name.
        $this->commandBus()->dispatch($this->edit(1, 'Buty trailowe'));
        self::assertSame(['draft', 2, 'staff-sub-2', 'buty-trailowe'], $this->row());
        $this->commandBus()->dispatch(new PublishProduct(self::PRODUCT_ID, 2, 'staff-sub-3'));
        self::assertSame(['published', 3, 'staff-sub-3', 'buty-trailowe'], $this->row());
        $this->commandBus()->dispatch(new UnpublishProduct(self::PRODUCT_ID, 3, 'staff-sub-4'));
        self::assertSame(['draft', 4, 'staff-sub-4', 'buty-trailowe'], $this->row());

        $events = $this->outboxEvents();
        self::assertCount(3, $events);
        foreach ($events as $event) {
            self::assertInstanceOf(ProductChanged::class, $event);
            self::assertSame([self::PRODUCT_ID, 'buty-trailowe'], [$event->productId, $event->slug]);
        }
    }

    public function testPublishOfAPublishedProductAndUnpublishOfADraftLeaveNothing(): void
    {
        $this->commandBus()->dispatch(new UnpublishProduct(self::PRODUCT_ID, 1, self::ACTOR));
        $this->commandBus()->dispatch(new PublishProduct(self::PRODUCT_ID, 1, self::ACTOR));
        $this->outboxEvents();

        $this->commandBus()->dispatch(new PublishProduct(self::PRODUCT_ID, 2, 'staff-sub-9'));

        self::assertSame(['published', 2, self::ACTOR, 'buty-biegowe'], $this->row());
        self::assertSame([], $this->outboxEvents());
    }

    public function testRemovalOfAVariantWithStockIsVariantInUseThroughInventoryAndLeavesNothing(): void
    {
        StockFixture::stock($this->connection(), 3, variantId: self::WHITE);
        $this->outboxEvents();

        try {
            $this->commandBus()->dispatch($this->edit(1, 'Buty biegowe', [['variantId' => self::BLACK, 'values' => ['czarne']]]));
            self::fail('Expected VariantInUse.');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(VariantInUse::class, $e->getPrevious());
            self::assertSame('Wariant „białe” ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.', $e->getPrevious()->getMessage());
        }

        self::assertSame(['draft', 1, self::ACTOR, 'buty-biegowe'], $this->row());
        self::assertSame(2, $this->connection()->fetchOne('SELECT count(*) FROM catalog_product_variant WHERE product_id = ?', [self::PRODUCT_ID]));
        self::assertSame([], $this->outboxEvents());
    }

    public function testTheRelaySendsTheContractToRabbitMq(): void
    {
        $before = time();
        $this->commandBus()->dispatch(new PublishProduct(self::PRODUCT_ID, 1, self::ACTOR));
        $this->consume('outbox');
        $this->consume('outbox');

        $messages = $this->drainEvents();

        self::assertCount(2, $messages);
        self::assertInstanceOf(ProductCreated::class, $messages[0]);
        $changed = $messages[1];
        self::assertInstanceOf(ProductChanged::class, $changed);
        self::assertSame([self::PRODUCT_ID, 'buty-biegowe'], [$changed->productId, $changed->slug]);
        self::assertGreaterThanOrEqual($before, $changed->occurredAt->getTimestamp());
    }

    /**
     * @param list<array{variantId: string|null, values: list<string>}>|null $variants
     */
    private function edit(int $version, string $name, ?array $variants = null): EditProduct
    {
        return new EditProduct(
            self::PRODUCT_ID,
            $version,
            'staff-sub-2',
            $name,
            '',
            '',
            self::CATEGORY_ID,
            [],
            'standard',
            [['key' => self::IMAGE, 'alt' => 'front']],
            ['Kolor'],
            $variants ?? [['variantId' => self::BLACK, 'values' => ['czarne']], ['variantId' => self::WHITE, 'values' => ['białe']]],
        );
    }

    /**
     * @return list<mixed> status, version, updated_by and slug of the stored product
     */
    private function row(): array
    {
        $row = $this->connection()->fetchNumeric('SELECT status, version, updated_by, slug FROM catalog_product WHERE id = ?', [self::PRODUCT_ID]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * Takes every event off the outbox (oldest first); the next call sees only later ones.
     *
     * @return list<object>
     */
    private function outboxEvents(): array
    {
        $outbox = $this->transport('outbox');
        $events = [];
        while ([] !== $envelopes = [...$outbox->get()]) {
            foreach ($envelopes as $envelope) {
                $events[] = $envelope->getMessage();
                $outbox->ack($envelope);
            }
        }

        return $events;
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
