<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Events;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\EditProduct;
use App\Catalog\Domain\Product\ProductStatus;
use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The stock records of a product on the real route: CreateProduct and EditProduct → the integration events in the
 * outbox (Postgres) → relay → RabbitMQ → SyncStockRecordsOnProductChange → a record with the product for every variant.
 */
final class StockRecordsFlowTest extends MessagingKernelTestCase
{
    private const string CATEGORY_ID = '0192f0c4-0000-7000-8000-00000000c501';
    private const string PRODUCT_ID = '0192f0c4-0000-7000-8000-00000000a501';
    private const string GREEN = '0192f0c4-0000-7000-8000-00000000b501';
    private const string ORANGE = '0192f0c4-0000-7000-8000-00000000b502';

    protected function setUp(): void
    {
        parent::setUp();
        // The edit is invalidated in the store too; nothing to send in this test.
        self::getContainer()->set(HttpStorefrontCache::class, new SpyStorefrontCache());
        $this->commandBus()->dispatch(new CreateCategory(self::CATEGORY_ID, 'Kajaki', 'kajaki', null));
    }

    public function testACreatedAndThenEditedProductHasARecordWithTheProductForEveryVariant(): void
    {
        $this->commandBus()->dispatch(new CreateProduct(
            self::PRODUCT_ID,
            'Kajak Rzeka',
            'kajak-rzeka',
            '',
            self::CATEGORY_ID,
            [],
            'standard',
            [],
            ['Kolor'],
            [['id' => self::GREEN, 'values' => ['zielony']], ['id' => self::ORANGE, 'values' => ['pomarańczowy']]],
            ProductStatus::Draft,
            'staff-sub-1',
        ));

        // The relay of ProductCreated, then its consumers.
        $this->consumeOutboxAndEvents(2);

        self::assertSame([[self::GREEN, self::PRODUCT_ID, 0, 0], [self::ORANGE, self::PRODUCT_ID, 0, 0]], $this->records());

        $this->commandBus()->dispatch(new EditProduct(
            self::PRODUCT_ID,
            1,
            'staff-sub-2',
            'Kajak Rzeka',
            'kajak-rzeka',
            '',
            self::CATEGORY_ID,
            [],
            'standard',
            [],
            ['Kolor'],
            [['variantId' => self::GREEN, 'values' => ['zielony']], ['variantId' => null, 'values' => ['czarny']]],
        ));
        $this->consumeOutboxAndEvents(2);

        $black = $this->connection()->fetchOne('SELECT id FROM catalog_product_variant WHERE product_id = ? AND id <> ?', [self::PRODUCT_ID, self::GREEN]);
        self::assertIsString($black);
        // The removed orange variant lost its empty record.
        self::assertEqualsCanonicalizing([[self::GREEN, self::PRODUCT_ID, 0, 0], [$black, self::PRODUCT_ID, 0, 0]], $this->records());
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * @return list<list<mixed>> variant, product, physical and reserved of the records of the
     *                           product and of the orange variant, in the order of the variant ids
     */
    private function records(): array
    {
        return array_map(
            static fn (array $row): array => [$row['variant_id'], $row['product_id'], $row['physical'], $row['reserved']],
            $this->connection()->fetchAllAssociative(
                'SELECT variant_id, product_id, physical, reserved FROM inventory_stock_item WHERE product_id = ? OR variant_id = ? ORDER BY variant_id',
                [self::PRODUCT_ID, self::ORANGE],
            ),
        );
    }

    private function consumeOutboxAndEvents(int $messages): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('messenger:consume'));
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => $messages, '--time-limit' => 10, '--sleep' => 0.05]);
        $tester->assertCommandIsSuccessful();
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
