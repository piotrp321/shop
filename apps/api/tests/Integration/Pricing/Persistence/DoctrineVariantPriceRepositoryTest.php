<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineVariantPriceRepository;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Pricing\IndependentEntityManagers;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Support\StatementCounter;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class DoctrineVariantPriceRepositoryTest extends KernelTestCase
{
    use Factories;
    use IndependentEntityManagers;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineVariantPriceRepository $prices;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->prices = new DoctrineVariantPriceRepository($entityManager, new RecordingDomainEventPublisher());
    }

    public function testNewPriceIsStoredWithItsFirstHistoryEntry(): void
    {
        $price = $this->newPrice(5490, 'printed_book', PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        $id = $price->id()->toString();

        $this->prices->save($price);

        self::assertSame(1, $price->version());
        self::assertSame(
            ['currency' => 'PLN', 'gross_amount' => 5490, 'net_amount' => 5229, 'vat_amount' => 261, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'version' => 1],
            $this->connection->fetchAssociative('SELECT currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version FROM pricing_variant_price WHERE variant_id = ?', [$id]),
        );
        $history = $this->connection->fetchAllAssociative('SELECT id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, source, actor_id, actor_name, changed_at FROM pricing_price_history WHERE variant_id = ?', [$id]);
        self::assertCount(1, $history);
        self::assertIsString($history[0]['id']);
        self::assertTrue(Uuid::isValid($history[0]['id']));
        unset($history[0]['id'], $history[0]['changed_at']);
        self::assertSame(
            ['currency' => 'PLN', 'gross_amount' => 5490, 'net_amount' => 5229, 'vat_amount' => 261, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'source' => 'staff', 'actor_id' => 'sub-1', 'actor_name' => 'Anna Admin'],
            $history[0],
        );
    }

    public function testStoredPriceIsReadBack(): void
    {
        $price = $this->newPrice(12300, 'standard', PriceChangeOrigin::seed());
        $this->prices->save($price);
        $this->entityManager->clear();

        $read = $this->prices->find($price->id());

        self::assertNotNull($read);
        self::assertTrue($read->id()->equals($price->id()));
        self::assertTrue($read->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(12300), VatRate::fromBasisPoints(2300))));
        self::assertSame('standard', $read->taxClass()->toString());
        self::assertSame(1, $read->version());
        self::assertSame($price->updatedAt()->getTimestamp(), $read->updatedAt()->getTimestamp());
        self::assertSame([], $read->releaseHistory());
    }

    public function testUnknownPriceIsNull(): void
    {
        self::assertNull($this->prices->find(VariantId::fromString((string) Uuid::v4())));
    }

    /**
     * Keyset pages by product id, read as stored: an outdated rate and a class without a rate come back unchanged.
     */
    public function testPagesFollowTheProductIdAfterTheCursor(): void
    {
        $this->connection->executeStatement('DELETE FROM pricing_price_history');
        $this->connection->executeStatement('DELETE FROM pricing_variant_price');
        $third = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => 'f0000000-0000-4000-8000-000000000003', 'gross' => 990, 'taxClass' => 'retired_class', 'vatRateBp' => 700]);
        $first = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => '10000000-0000-4000-8000-000000000001', 'gross' => 12300]);
        $second = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => 'a0000000-0000-4000-8000-000000000002', 'gross' => 5490, 'taxClass' => 'printed_book', 'vatRateBp' => 2300]);
        $this->entityManager->clear();

        $firstPage = $this->prices->page(null, 2);
        $secondPage = $this->prices->page(VariantId::fromString($second), 2);

        self::assertSame([$first, $second], array_map(static fn (VariantPrice $price): string => $price->id()->toString(), $firstPage));
        self::assertSame([$third], array_map(static fn (VariantPrice $price): string => $price->id()->toString(), $secondPage));
        self::assertSame([], $this->prices->page(VariantId::fromString($third), 2));
        $outdated = $firstPage[1];
        self::assertTrue($outdated->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints(2300))));
        self::assertSame('printed_book', $outdated->taxClass()->toString());
        self::assertSame(1, $outdated->version());
        self::assertFalse($outdated->isCurrent(new PolishVatRates()));
        self::assertSame('retired_class', $secondPage[0]->taxClass()->toString());
        self::assertSame(700, $secondPage[0]->breakdown()->rate()->basisPoints());
    }

    public function testChangeIncrementsTheVersionAndAppendsOneEntry(): void
    {
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $this->prices->save($price);
        $this->entityManager->clear();

        $read = $this->prices->find($price->id());
        self::assertNotNull($read);
        $read->changeGross(GrossPrice::pln(12300), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        $this->prices->save($read);

        self::assertSame(2, $read->version());
        self::assertSame(2, $this->committedValue('SELECT version FROM pricing_variant_price WHERE variant_id = ?', $price));
        self::assertSame(12300, $this->committedValue('SELECT gross_amount FROM pricing_variant_price WHERE variant_id = ?', $price));
        self::assertSame([10000, 12300], $this->connection->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id', [$price->id()->toString()]));
        $this->entityManager->clear();
        self::assertSame(2, $this->prices->find($price->id())?->version());
    }

    public function testSavingAnUnchangedPriceWritesNothing(): void
    {
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $this->prices->save($price);
        $this->entityManager->clear();
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $writesBefore = $counter->writes();

        $read = $this->prices->find($price->id());
        self::assertNotNull($read);
        self::assertFalse($read->changeGross(GrossPrice::pln(10000), new PolishVatRates(), PriceChangeOrigin::seed()));
        $this->prices->save($read);

        self::assertSame(0, $counter->writes() - $writesBefore);
        self::assertSame(1, $this->committedValue('SELECT version FROM pricing_variant_price WHERE variant_id = ?', $price));
        self::assertSame(1, $this->committedValue('SELECT COUNT(*) FROM pricing_price_history WHERE variant_id = ?', $price));
    }

    /**
     * Two requests read version 1; the first saves, the second is refused by the versioned UPDATE. With
     * $insideOuterTransaction the second runs as a handler on command.bus does (doctrine_transaction middleware).
     */
    #[DataProvider('outerTransaction')]
    public function testSecondChangeBasedOnTheSameVersionIsAConflict(bool $insideOuterTransaction): void
    {
        $first = $this->independentManager();
        $second = $this->independentManager();
        $firstPrices = new DoctrineVariantPriceRepository($first, new RecordingDomainEventPublisher());
        $secondPrices = new DoctrineVariantPriceRepository($second, new RecordingDomainEventPublisher());
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $this->committedVariantIds[] = $price->id()->toString();
        $firstPrices->save($price);
        $first->clear();

        $readByFirst = $firstPrices->find($price->id());
        $readBySecond = $secondPrices->find($price->id());
        self::assertNotNull($readByFirst);
        self::assertNotNull($readBySecond);
        $readByFirst->changeGross(GrossPrice::pln(11000), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'First'));
        $readBySecond->changeGross(GrossPrice::pln(12000), new PolishVatRates(), PriceChangeOrigin::staff('sub-2', 'Second'));
        $firstPrices->save($readByFirst);

        try {
            $insideOuterTransaction
                ? $second->wrapInTransaction(static fn () => $secondPrices->save($readBySecond))
                : $secondPrices->save($readBySecond);
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        $id = $price->id()->toString();
        self::assertSame(['gross_amount' => 11000, 'version' => 2], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$id]));
        self::assertSame([10000, 11000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id', [$id]));
        self::assertFalse($second->getConnection()->isTransactionActive());
        $verify->close();
    }

    /** @return iterable<string, array{bool}> */
    public static function outerTransaction(): iterable
    {
        yield 'alone' => [false];
        yield 'inside an outer transaction' => [true];
    }

    /**
     * Both requests see no price; the first commits its price before the second saves. The second write re-reads
     * the row inside its transaction, finds the stored price and refuses (the explicit check, not the primary key).
     */
    public function testSecondFirstPriceOfTheSameProductIsAConflict(): void
    {
        $first = $this->independentManager();
        $second = $this->independentManager();
        $firstPrices = new DoctrineVariantPriceRepository($first, new RecordingDomainEventPublisher());
        $secondPrices = new DoctrineVariantPriceRepository($second, new RecordingDomainEventPublisher());
        $id = VariantId::fromString((string) Uuid::v4());
        $this->committedVariantIds[] = $id->toString();

        // Both requests see no price and create one.
        self::assertNull($firstPrices->find($id));
        self::assertNull($secondPrices->find($id));
        $firstPrices->save(VariantPrice::create($id, GrossPrice::pln(10000), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'First')));

        try {
            $secondPrices->save(VariantPrice::create($id, GrossPrice::pln(20000), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::staff('sub-2', 'Second')));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        self::assertSame(['gross_amount' => 10000, 'version' => 1], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$id->toString()]));
        self::assertSame([10000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ?', [$id->toString()]));
        $verify->close();
    }

    /**
     * The competing request commits its first price after the write under test has re-read the row (no price) and
     * before it flushes: the INSERT hits the primary key (23505 on pricing_variant_price_pkey), which ends in
     * PriceModifiedConcurrently.
     */
    public function testFirstPriceCommittedBetweenTheReadAndTheInsertIsRefusedByThePrimaryKey(): void
    {
        $underTest = $this->independentManager();
        $competing = new DoctrineVariantPriceRepository($this->independentManager(), new RecordingDomainEventPublisher());
        $id = VariantId::fromString((string) Uuid::v4());
        $this->committedVariantIds[] = $id->toString();
        $listener = new class(static function () use ($competing, $id): void {
            $competing->save(VariantPrice::create($id, GrossPrice::pln(12000), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::staff('sub-2', 'Second')));
        }) {
            public int $calls = 0;

            public function __construct(private readonly \Closure $competingRequest)
            {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                if (1 === ++$this->calls) {
                    ($this->competingRequest)();
                }
            }
        };
        $eventManager = $underTest->getEventManager();
        \assert($eventManager instanceof EventManager);
        $eventManager->addEventListener(Events::preFlush, $listener);

        try {
            new DoctrineVariantPriceRepository($underTest, new RecordingDomainEventPublisher())->save(
                VariantPrice::create($id, GrossPrice::pln(11000), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'First')),
            );
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertSame(1, $listener->calls);
        $verify = $this->independentConnection();
        self::assertSame([['gross_amount' => 12000, 'version' => 1]], $verify->fetchAllAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$id->toString()]));
        // One success, one entry.
        self::assertSame([12000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ?', [$id->toString()]));
        $verify->close();
    }

    /**
     * Two aggregates of the same price read in one entity manager: after the first is saved, the identity map holds
     * version 2, while the second was read at version 1. Its write must compare with version 1 and be refused,
     * not overwrite the first change.
     */
    public function testStaleAggregateIsRefusedEvenWhenTheIdentityMapHoldsTheNewerVersion(): void
    {
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $this->prices->save($price);
        $this->entityManager->clear();
        $first = $this->prices->find($price->id());
        $second = $this->prices->find($price->id());
        self::assertNotNull($first);
        self::assertNotNull($second);
        $first->changeGross(GrossPrice::pln(11000), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'First'));
        $this->prices->save($first);
        $second->changeGross(GrossPrice::pln(12000), new PolishVatRates(), PriceChangeOrigin::staff('sub-2', 'Second'));

        try {
            $this->prices->save($second);
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $id = $price->id()->toString();
        self::assertSame(['gross_amount' => 11000, 'version' => 2], $this->connection->fetchAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$id]));
        self::assertSame([10000, 11000], $this->connection->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id', [$id]));
    }

    /**
     * The history insert fails at commit (a deferred trigger of the test), after the price row was written: the
     * whole transaction is rolled back.
     */
    #[DataProvider('firstOrChange')]
    public function testFailedHistoryWriteRollsBackThePrice(bool $change): void
    {
        $manager = $this->independentManager();
        $prices = new DoctrineVariantPriceRepository($manager, new RecordingDomainEventPublisher());
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $id = $price->id()->toString();
        $this->committedVariantIds[] = $id;
        if ($change) {
            $prices->save($price);
            $manager->clear();
            $price = $prices->find($price->id());
            self::assertNotNull($price);
            $price->changeGross(GrossPrice::pln(4242), new PolishVatRates(), PriceChangeOrigin::seed());
        }
        $this->failHistoryWritesAtCommit($id);

        try {
            $prices->save($price);
            self::fail('Expected the commit to fail.');
        } catch (DbalException $e) {
            self::assertStringContainsString('history write refused by the test', $e->getMessage());
        }

        $verify = $this->independentConnection();
        self::assertSame(
            $change ? [['gross_amount' => 10000, 'version' => 1]] : [],
            $verify->fetchAllAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$id]),
        );
        self::assertSame($change ? [10000] : [], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ?', [$id]));
        $verify->close();
    }

    /** @return iterable<string, array{bool}> */
    public static function firstOrChange(): iterable
    {
        yield 'first price' => [false];
        yield 'change of a price' => [true];
    }

    private function failHistoryWritesAtCommit(string $productId): void
    {
        $setup = $this->independentConnection();
        $setup->executeStatement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pricing_test_refuse_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'history write refused by the test';
            END;
            $$
            SQL);
        $setup->executeStatement(\sprintf(
            "CREATE CONSTRAINT TRIGGER pricing_test_refuse_history AFTER INSERT ON pricing_price_history DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.variant_id = '%s') EXECUTE FUNCTION pricing_test_refuse_history()",
            VariantId::fromString($productId)->toString(),
        ));
        $setup->close();
        $this->cleanupStatements[] = 'DROP TRIGGER IF EXISTS pricing_test_refuse_history ON pricing_price_history';
        $this->cleanupStatements[] = 'DROP FUNCTION IF EXISTS pricing_test_refuse_history()';
    }

    private function newPrice(int $gross, string $taxClass, PriceChangeOrigin $origin): VariantPrice
    {
        return VariantPrice::create(VariantId::fromString((string) Uuid::v4()), GrossPrice::pln($gross), TaxClass::fromString($taxClass), new PolishVatRates(), $origin);
    }

    private function committedValue(string $sql, VariantPrice $price): mixed
    {
        return $this->connection->fetchOne($sql, [$price->id()->toString()]);
    }
}
