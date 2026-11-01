<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineProductPriceRepository;
use App\Tests\Integration\Pricing\IndependentEntityManagers;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineProductPriceRepositoryTest extends KernelTestCase
{
    use IndependentEntityManagers;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineProductPriceRepository $prices;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->prices = new DoctrineProductPriceRepository($entityManager);
    }

    public function testNewPriceIsStoredWithItsFirstHistoryEntry(): void
    {
        $price = $this->newPrice(5490, 'printed_book', PriceChangeOrigin::staff('sub-1', 'Anna Admin'));
        $id = $price->id()->toString();

        $this->prices->save($price);

        self::assertSame(1, $price->version());
        self::assertSame(
            ['currency' => 'PLN', 'gross_amount' => 5490, 'net_amount' => 5229, 'vat_amount' => 261, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'version' => 1],
            $this->connection->fetchAssociative('SELECT currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version FROM pricing_product_price WHERE product_id = ?', [$id]),
        );
        $history = $this->connection->fetchAllAssociative('SELECT id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, source, actor_id, actor_name, changed_at FROM pricing_price_history WHERE product_id = ?', [$id]);
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
        self::assertNull($this->prices->find(ProductId::fromString((string) Uuid::v4())));
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
        self::assertSame(2, $this->committedValue('SELECT version FROM pricing_product_price WHERE product_id = ?', $price));
        self::assertSame(12300, $this->committedValue('SELECT gross_amount FROM pricing_product_price WHERE product_id = ?', $price));
        self::assertSame([10000, 12300], $this->connection->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ? ORDER BY changed_at, id', [$price->id()->toString()]));
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
        self::assertSame(1, $this->committedValue('SELECT version FROM pricing_product_price WHERE product_id = ?', $price));
        self::assertSame(1, $this->committedValue('SELECT COUNT(*) FROM pricing_price_history WHERE product_id = ?', $price));
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
        $firstPrices = new DoctrineProductPriceRepository($first);
        $secondPrices = new DoctrineProductPriceRepository($second);
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $this->committedProductIds[] = $price->id()->toString();
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
        self::assertSame(['gross_amount' => 11000, 'version' => 2], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_product_price WHERE product_id = ?', [$id]));
        self::assertSame([10000, 11000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ? ORDER BY changed_at, id', [$id]));
        self::assertFalse($second->getConnection()->isTransactionActive());
        $verify->close();
    }

    /** @return iterable<string, array{bool}> */
    public static function outerTransaction(): iterable
    {
        yield 'alone' => [false];
        yield 'inside an outer transaction' => [true];
    }

    public function testSecondFirstPriceOfTheSameProductIsAConflict(): void
    {
        $first = $this->independentManager();
        $second = $this->independentManager();
        $firstPrices = new DoctrineProductPriceRepository($first);
        $secondPrices = new DoctrineProductPriceRepository($second);
        $id = ProductId::fromString((string) Uuid::v4());
        $this->committedProductIds[] = $id->toString();

        // Both requests see no price and create one.
        self::assertNull($firstPrices->find($id));
        self::assertNull($secondPrices->find($id));
        $firstPrices->save(ProductPrice::create($id, GrossPrice::pln(10000), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::staff('sub-1', 'First')));

        try {
            $secondPrices->save(ProductPrice::create($id, GrossPrice::pln(20000), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::staff('sub-2', 'Second')));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        self::assertSame(['gross_amount' => 10000, 'version' => 1], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_product_price WHERE product_id = ?', [$id->toString()]));
        self::assertSame([10000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ?', [$id->toString()]));
        $verify->close();
    }

    /**
     * The history insert fails at commit (a deferred trigger of the test), after the price row was written: the
     * whole transaction is rolled back.
     */
    #[DataProvider('firstOrChange')]
    public function testFailedHistoryWriteRollsBackThePrice(bool $change): void
    {
        $manager = $this->independentManager();
        $prices = new DoctrineProductPriceRepository($manager);
        $price = $this->newPrice(10000, 'standard', PriceChangeOrigin::seed());
        $id = $price->id()->toString();
        $this->committedProductIds[] = $id;
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
            $verify->fetchAllAssociative('SELECT gross_amount, version FROM pricing_product_price WHERE product_id = ?', [$id]),
        );
        self::assertSame($change ? [10000] : [], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ?', [$id]));
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
            "CREATE CONSTRAINT TRIGGER pricing_test_refuse_history AFTER INSERT ON pricing_price_history DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.product_id = '%s') EXECUTE FUNCTION pricing_test_refuse_history()",
            ProductId::fromString($productId)->toString(),
        ));
        $setup->close();
        $this->cleanupStatements[] = 'DROP TRIGGER IF EXISTS pricing_test_refuse_history ON pricing_price_history';
        $this->cleanupStatements[] = 'DROP FUNCTION IF EXISTS pricing_test_refuse_history()';
    }

    private function newPrice(int $gross, string $taxClass, PriceChangeOrigin $origin): ProductPrice
    {
        return ProductPrice::create(ProductId::fromString((string) Uuid::v4()), GrossPrice::pln($gross), TaxClass::fromString($taxClass), new PolishVatRates(), $origin);
    }

    private function committedValue(string $sql, ProductPrice $price): mixed
    {
        return $this->connection->fetchOne($sql, [$price->id()->toString()]);
    }
}
