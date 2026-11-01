<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Command\SetProductPrice;
use App\Pricing\Application\Command\SetProductPriceHandler;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductNotFound;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineProductPriceRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Unit\Pricing\InMemoryProductTaxClasses;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * SetProductPrice on PostgreSQL: through the real command bus (shop_test, rolled back), and as two concurrent
 * requests on their own connections (committed, deleted after the test).
 */
final class SetProductPriceTest extends KernelTestCase
{
    use Factories;
    use IndependentEntityManagers;

    private MessageBusInterface $commandBus;
    private Connection $connection;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->commandBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    public function testTwoChangesFromTheSameVersionThroughTheCommandBusGiveOneSuccessAndAConflict(): void
    {
        $productId = $this->catalogProduct(TaxClassCode::PrintedBook);
        $this->dispatch(SetProductPrice::byStaff($productId, 5490, null, 'sub-1', 'Anna Admin'));

        $this->dispatch(SetProductPrice::byStaff($productId, 5990, 1, 'sub-1', 'Anna Admin'));
        try {
            $this->dispatch(SetProductPrice::byStaff($productId, 6490, 1, 'sub-2', 'Jan Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertSame(
            ['gross_amount' => 5990, 'net_amount' => 5705, 'vat_amount' => 285, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'version' => 2],
            $this->connection->fetchAssociative('SELECT gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version FROM pricing_product_price WHERE product_id = ?', [$productId]),
        );
        self::assertSame([5490, 5990], $this->connection->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ? ORDER BY changed_at, id', [$productId]));
    }

    public function testSameAmountThroughTheCommandBusWritesNoEntry(): void
    {
        $productId = $this->catalogProduct(TaxClassCode::Standard);
        $this->dispatch(SetProductPrice::bySeed($productId, 12300));

        $this->dispatch(SetProductPrice::byStaff($productId, 12300, 1, 'sub-1', 'Anna Admin'));

        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_product_price WHERE product_id = ?', [$productId]));
        self::assertSame(
            [['source' => 'seed', 'actor_id' => null, 'actor_name' => null]],
            $this->connection->fetchAllAssociative('SELECT source, actor_id, actor_name FROM pricing_price_history WHERE product_id = ?', [$productId]),
        );
    }

    public function testUnknownProductThroughTheCommandBusIsNotFound(): void
    {
        $productId = (string) Uuid::v4();

        try {
            $this->dispatch(SetProductPrice::byStaff($productId, 100, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected ProductNotFound.');
        } catch (ProductNotFound) {
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_product_price WHERE product_id = ?', [$productId]));
    }

    /**
     * Both requests read version 1 and pass the handler's check; the versioned UPDATE refuses the later one.
     */
    public function testConcurrentChangesOfTheSameVersionGiveOneSuccessAndAConflict(): void
    {
        $productId = (string) Uuid::v4();
        $this->committedProductIds[] = $productId;
        $this->handler($this->independentManager(), $productId)(SetProductPrice::bySeed($productId, 10000));
        $competing = $this->handler($this->independentManager(), $productId);
        $underTest = $this->handler($this->independentManager(), $productId, static function () use ($competing, $productId): void {
            $competing(SetProductPrice::byStaff($productId, 12000, 1, 'sub-2', 'Jan Admin'));
        });

        try {
            $underTest(SetProductPrice::byStaff($productId, 11000, 1, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        self::assertSame(['gross_amount' => 12000, 'version' => 2], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_product_price WHERE product_id = ?', [$productId]));
        // The first price and the one successful change.
        self::assertSame([10000, 12000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ? ORDER BY changed_at, id', [$productId]));
        $verify->close();
    }

    /**
     * Both requests see no price and create one; the primary key refuses the later one.
     */
    public function testConcurrentFirstPricesGiveOneSuccessAndAConflict(): void
    {
        $productId = (string) Uuid::v4();
        $this->committedProductIds[] = $productId;
        $competing = $this->handler($this->independentManager(), $productId);
        $underTest = $this->handler($this->independentManager(), $productId, static function () use ($competing, $productId): void {
            $competing(SetProductPrice::byStaff($productId, 12000, null, 'sub-2', 'Jan Admin'));
        });

        try {
            $underTest(SetProductPrice::byStaff($productId, 11000, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        self::assertSame(['gross_amount' => 12000, 'version' => 1], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_product_price WHERE product_id = ?', [$productId]));
        self::assertSame([12000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE product_id = ?', [$productId]));
        $verify->close();
    }

    /**
     * A handler as one request: its own entity manager and connection, inside a transaction like the
     * doctrine_transaction middleware of command.bus.
     *
     * @param (\Closure(): void)|null $afterRead the competing request, run after this one has read the price
     *
     * @return \Closure(SetProductPrice): void
     */
    private function handler(EntityManagerInterface $entityManager, string $productId, ?\Closure $afterRead = null): \Closure
    {
        $handler = new SetProductPriceHandler(
            new InterleavingProductPriceRepository(new DoctrineProductPriceRepository($entityManager), $afterRead),
            new InMemoryProductTaxClasses([$productId => 'standard']),
            new PolishVatRates(),
        );

        return static function (SetProductPrice $command) use ($entityManager, $handler): void {
            $entityManager->wrapInTransaction(static fn () => $handler($command));
        };
    }

    private function dispatch(SetProductPrice $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
    }

    private function catalogProduct(TaxClassCode $taxClass): string
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => $taxClass]);
        new DoctrineProductRepository($entityManager)->save($product);

        return $product->id()->toString();
    }
}
