<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\EditProduct;
use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Domain\Product\ProductStatus;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\VariantPriceChanged;
use App\Pricing\Domain\Tax\VatRate;
use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use App\Tests\Support\OriginalImages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * A change of the tax class of a product on the real route (decision K6): EditProduct → ProductChanged in the outbox
 * (Postgres) → relay → RabbitMQ → RepriceOnTaxClassChange → the prices of the product's variants through Doctrine,
 * with their history and VariantPriceChanged in the outbox.
 */
final class RepriceOnTaxClassChangeTest extends MessagingKernelTestCase
{
    use Factories;

    private const string CATEGORY_ID = '0192f0c4-0000-7000-8000-00000000c101';
    private const string PRODUCT_ID = '0192f0c4-0000-7000-8000-00000000a101';
    private const string HARDCOVER = '0192f0c4-0000-7000-8000-00000000b101';
    private const string PAPERBACK = '0192f0c4-0000-7000-8000-00000000b102';
    private const string IMAGE = 'originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        OriginalImages::exist(self::getContainer(), self::IMAGE);
        // The product change is invalidated in the store too; nothing to send in this test.
        self::getContainer()->set(HttpStorefrontCache::class, new SpyStorefrontCache());
        $this->commandBus()->dispatch(new CreateCategory(self::CATEGORY_ID, 'Książki', 'ksiazki', null));
        $this->commandBus()->dispatch(new CreateProduct(
            self::PRODUCT_ID,
            'Atlas Tatr',
            '',
            '',
            self::CATEGORY_ID,
            [],
            'standard',
            [['key' => self::IMAGE, 'alt' => 'okładka']],
            ['Okładka'],
            [['id' => self::HARDCOVER, 'values' => ['twarda']], ['id' => self::PAPERBACK, 'values' => ['miękka']]],
            // A draft: its prices are set before the publication, and repriced too.
            ProductStatus::Draft,
            'staff-sub-1',
        ));
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        VariantPriceRecordFactory::store($entityManager, ['variantId' => self::HARDCOVER, 'gross' => 8990, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        VariantPriceRecordFactory::store($entityManager, ['variantId' => self::PAPERBACK, 'gross' => 5490, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        $entityManager->clear();
        $this->outboxEvents();
    }

    public function testAClassChangeRepricesThePricesWithTheSameGrossAndATaxReconciliationEntryAndARepeatedEventChangesNothing(): void
    {
        $this->commandBus()->dispatch($this->editTaxClass('printed_book'));

        // The relay of ProductChanged, then its consumers.
        $this->consumeOutboxAndEvents(2);

        self::assertSame($this->stored(8990, 500, 'printed_book', 2), $this->price(self::HARDCOVER));
        self::assertSame($this->stored(5490, 500, 'printed_book', 2), $this->price(self::PAPERBACK));
        self::assertEqualsCanonicalizing(
            [[self::HARDCOVER, 8990, 'printed_book', 500, 'tax_reconciliation', null], [self::PAPERBACK, 5490, 'printed_book', 500, 'tax_reconciliation', null]],
            array_map(
                static fn (array $row): array => array_values($row),
                $this->connection()->fetchAllAssociative('SELECT variant_id, gross_amount, tax_class, vat_rate_bp, source, actor_id FROM pricing_price_history'),
            ),
        );
        $changes = $this->outboxEvents();
        self::assertCount(2, $changes);
        foreach ($changes as $change) {
            self::assertInstanceOf(VariantPriceChanged::class, $change);
            self::assertSame(PriceChangeSource::TaxReconciliation, $change->source);
        }
        self::assertSame(0, $this->rows('failed'));

        $this->publisher()->publish(new ProductChanged(self::PRODUCT_ID, 'atlas-tatr', new \DateTimeImmutable()));
        $this->consumeOutboxAndEvents(2);

        self::assertSame($this->stored(8990, 500, 'printed_book', 2), $this->price(self::HARDCOVER));
        self::assertSame(2, $this->connection()->fetchOne('SELECT count(*) FROM pricing_price_history'));
        self::assertSame([], $this->outboxEvents());
        self::assertSame(0, $this->rows('failed'));
    }

    private function editTaxClass(string $taxClass): EditProduct
    {
        return new EditProduct(
            self::PRODUCT_ID,
            1,
            'staff-sub-2',
            'Atlas Tatr',
            'atlas-tatr',
            '',
            self::CATEGORY_ID,
            [],
            $taxClass,
            [['key' => self::IMAGE, 'alt' => 'okładka']],
            ['Okładka'],
            [['variantId' => self::HARDCOVER, 'values' => ['twarda']], ['variantId' => self::PAPERBACK, 'values' => ['miękka']]],
        );
    }

    /**
     * @return array{gross_amount: int, net_amount: int, vat_amount: int, vat_rate_bp: int, tax_class: string, version: int}
     */
    private function stored(int $gross, int $basisPoints, string $taxClass, int $version): array
    {
        $breakdown = PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints));

        return [
            'gross_amount' => $gross,
            'net_amount' => $breakdown->net()->amount(),
            'vat_amount' => $breakdown->vat()->amount(),
            'vat_rate_bp' => $basisPoints,
            'tax_class' => $taxClass,
            'version' => $version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $variantId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * Takes every event off the outbox (oldest first) without relaying it; the next call sees only later ones.
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

    private function consumeOutboxAndEvents(int $messages): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
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
