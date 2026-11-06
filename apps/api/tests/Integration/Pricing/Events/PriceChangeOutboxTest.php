<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Events;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\TaxReconciliation\ReconcileTax;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceChanged;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Infrastructure\Catalog\QueryBusCatalogVariants;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrinePersistenceSession;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineVariantPriceRepository;
use App\Pricing\UI\Console\ReconcileTaxCommand;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * VariantPriceChanged goes to the outbox (the real Doctrine transport of the `test_messaging` kernel) in the
 * transaction of the price write: a committed save leaves one event, a rolled back or refused one leaves none.
 */
final class PriceChangeOutboxTest extends MessagingKernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private DoctrineVariantPriceRepository $prices;

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->prices = new DoctrineVariantPriceRepository($entityManager, $this->publisher());
        // pricing:reconcile-tax goes through every stored price.
        $this->connection()->executeStatement('DELETE FROM pricing_price_history');
        $this->connection()->executeStatement('DELETE FROM pricing_variant_price');
    }

    public function testASavedPriceLeavesOneEventWithItsSourceInTheOutbox(): void
    {
        $price = $this->newPrice(PriceChangeOrigin::staff('sub-1', 'Anna Admin'));

        $this->prices->save($price);

        self::assertSame([[$price->id()->toString(), PriceChangeSource::Staff]], $this->outboxEvents());
    }

    public function testEveryChangeSavedIsOneEvent(): void
    {
        $price = $this->newPrice(PriceChangeOrigin::seed());
        $this->prices->save($price);
        $price->changeGross(GrossPrice::pln(7777), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        $price->changeGross(GrossPrice::pln(8888), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        $this->prices->save($price);
        // Unchanged: nothing is written, nothing published.
        $this->prices->save($price);

        $id = $price->id()->toString();
        self::assertSame([[$id, PriceChangeSource::Seed], [$id, PriceChangeSource::Staff]], $this->outboxEvents());
    }

    public function testARolledBackWriteTransactionLeavesNoEvent(): void
    {
        $price = $this->newPrice(PriceChangeOrigin::seed());

        $failure = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($price): void {
                $this->prices->save($price);
                self::assertSame(1, $this->rows('outbox'), 'the event is written in the transaction');

                throw new \LogicException('Failure before commit.');
            });
        } catch (\LogicException $e) {
            $failure = $e;
        }

        self::assertSame('Failure before commit.', $failure->getMessage());
        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM pricing_variant_price WHERE variant_id = ?', [$price->id()->toString()]));
    }

    /**
     * pricing:reconcile-tax saves outside command.bus. A staff change lands right before each of its two saves of the
     * price (the change is plain SQL here, so it publishes nothing itself): both saves are refused by the version and
     * the outbox stays empty, while a price reconciled without a conflict leaves its event.
     */
    public function testAReconcileTaxSaveRefusedByAVersionConflictLeavesNoEvent(): void
    {
        $conflicting = $this->catalogVariant();
        $clean = $this->catalogVariant();
        foreach ([$conflicting, $clean] as $id) {
            VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $id, 'gross' => 5490, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        }
        $this->entityManager->clear();
        $connection = $this->connection();
        $prices = new class($this->prices, $conflicting, static function (string $id) use ($connection): void {
            $connection->executeStatement('UPDATE pricing_variant_price SET gross_amount = gross_amount + 100, net_amount = net_amount + 100, version = version + 1 WHERE variant_id = ?', [$id]);
        }) implements VariantPriceRepository {
            /**
             * @param \Closure(string): void $change
             */
            public function __construct(private readonly VariantPriceRepository $inner, private readonly string $conflicting, private readonly \Closure $change)
            {
            }

            public function find(VariantId $id): ?VariantPrice
            {
                return $this->inner->find($id);
            }

            public function findMany(array $ids): array
            {
                return $this->inner->findMany($ids);
            }

            public function page(?VariantId $after, int $limit): array
            {
                return $this->inner->page($after, $limit);
            }

            public function save(VariantPrice $price): void
            {
                if ($price->id()->toString() === $this->conflicting) {
                    ($this->change)($this->conflicting);
                }
                $this->inner->save($price);
            }
        };

        $tester = $this->reconcileTax($prices);

        self::assertSame(1, $tester->execute(['--apply' => true]), $tester->getDisplay());
        self::assertStringContainsString($conflicting.': standard 2300 bp -> printed_book 500 bp: not fixed', $tester->getDisplay());
        self::assertSame([[$clean, PriceChangeSource::TaxReconciliation]], $this->outboxEvents());
    }

    private function newPrice(PriceChangeOrigin $origin): VariantPrice
    {
        return VariantPrice::create(VariantId::fromString((string) Uuid::v7()), GrossPrice::pln(5490), TaxClass::fromString('standard'), new PolishVatRates(), $origin);
    }

    /**
     * @return list<array{string, PriceChangeSource}> the events in the outbox, oldest first
     */
    private function outboxEvents(): array
    {
        $events = [];
        // The Doctrine transport hands out the oldest message not taken yet: read until there is none.
        while ([] !== $envelopes = [...$this->transport('outbox')->get()]) {
            foreach ($envelopes as $envelope) {
                $event = $envelope->getMessage();
                self::assertInstanceOf(VariantPriceChanged::class, $event);
                $events[] = [$event->variantId, $event->source];
            }
        }

        return $events;
    }

    private function reconcileTax(VariantPriceRepository $prices): CommandTester
    {
        $queryBus = self::getContainer()->get('query.bus');
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $application = new Application();
        $application->addCommand(new ReconcileTaxCommand(new ReconcileTax($prices, new QueryBusCatalogVariants($queryBus), new PolishVatRates(), new DoctrinePersistenceSession($registry))));

        return new CommandTester($application->find('pricing:reconcile-tax'));
    }

    private function catalogVariant(): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => TaxClassCode::PrintedBook]);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return $product->variants()->toList()[0]->id()->toString();
    }
}
