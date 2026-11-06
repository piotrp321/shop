<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Application\Command\SetVariantPriceHandler;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantNotFound;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineVariantPriceRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Unit\Pricing\InMemoryVariantTaxClasses;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * SetVariantPrice on PostgreSQL: through the real command bus (shop_test, rolled back), and as two concurrent
 * requests on their own connections (committed, deleted after the test).
 */
final class SetVariantPriceTest extends KernelTestCase
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
        $variantId = $this->catalogVariant(TaxClassCode::PrintedBook);
        $this->dispatch(SetVariantPrice::byStaff($variantId, 5490, null, 'sub-1', 'Anna Admin'));

        $this->dispatch(SetVariantPrice::byStaff($variantId, 5990, 1, 'sub-1', 'Anna Admin'));
        try {
            $this->dispatch(SetVariantPrice::byStaff($variantId, 6490, 1, 'sub-2', 'Jan Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        self::assertSame(
            ['gross_amount' => 5990, 'net_amount' => 5705, 'vat_amount' => 285, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'version' => 2],
            $this->connection->fetchAssociative('SELECT gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]),
        );
        self::assertSame([5490, 5990], $this->connection->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id', [$variantId]));
    }

    public function testSameAmountThroughTheCommandBusWritesNoEntry(): void
    {
        $variantId = $this->catalogVariant(TaxClassCode::Standard);
        $this->dispatch(SetVariantPrice::bySeed($variantId, 12300));

        $this->dispatch(SetVariantPrice::byStaff($variantId, 12300, 1, 'sub-1', 'Anna Admin'));

        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]));
        self::assertSame(
            [['source' => 'seed', 'actor_id' => null, 'actor_name' => null]],
            $this->connection->fetchAllAssociative('SELECT source, actor_id, actor_name FROM pricing_price_history WHERE variant_id = ?', [$variantId]),
        );
    }

    public function testUnknownVariantThroughTheCommandBusIsNotFound(): void
    {
        $variantId = (string) Uuid::v4();

        try {
            $this->dispatch(SetVariantPrice::byStaff($variantId, 100, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected VariantNotFound.');
        } catch (VariantNotFound) {
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price WHERE variant_id = ?', [$variantId]));
    }

    public function testTheIdOfAProductIsNotAVariantThroughTheCommandBus(): void
    {
        $variantId = $this->catalogVariant(TaxClassCode::Standard);
        $productId = $this->connection->fetchOne('SELECT product_id FROM catalog_product_variant WHERE id = ?', [$variantId]);
        self::assertIsString($productId);
        self::assertNotSame($variantId, $productId);

        try {
            $this->dispatch(SetVariantPrice::byStaff($productId, 100, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected VariantNotFound.');
        } catch (VariantNotFound) {
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price'));
    }

    /**
     * Both requests read version 1 and pass the handler's check; the versioned UPDATE refuses the later one.
     */
    public function testConcurrentChangesOfTheSameVersionGiveOneSuccessAndAConflict(): void
    {
        $variantId = (string) Uuid::v4();
        $this->committedVariantIds[] = $variantId;
        $this->handler($this->independentManager(), $variantId)(SetVariantPrice::bySeed($variantId, 10000));
        $competing = $this->handler($this->independentManager(), $variantId);
        $underTest = $this->handler($this->independentManager(), $variantId, static function () use ($competing, $variantId): void {
            $competing(SetVariantPrice::byStaff($variantId, 12000, 1, 'sub-2', 'Jan Admin'));
        });

        try {
            $underTest(SetVariantPrice::byStaff($variantId, 11000, 1, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        self::assertSame(['gross_amount' => 12000, 'version' => 2], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]));
        // The first price and the one successful change.
        self::assertSame([10000, 12000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id', [$variantId]));
        $verify->close();
    }

    /**
     * Both requests see no price and create one. The competing one commits before the request under test saves, so
     * its write re-reads the row inside the transaction and refuses; the primary key race (commit after that read)
     * is covered by DoctrineVariantPriceRepositoryTest.
     */
    public function testConcurrentFirstPricesGiveOneSuccessAndAConflict(): void
    {
        $variantId = (string) Uuid::v4();
        $this->committedVariantIds[] = $variantId;
        $competing = $this->handler($this->independentManager(), $variantId);
        $underTest = $this->handler($this->independentManager(), $variantId, static function () use ($competing, $variantId): void {
            $competing(SetVariantPrice::byStaff($variantId, 12000, null, 'sub-2', 'Jan Admin'));
        });

        try {
            $underTest(SetVariantPrice::byStaff($variantId, 11000, null, 'sub-1', 'Anna Admin'));
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
        }

        $verify = $this->independentConnection();
        self::assertSame(['gross_amount' => 12000, 'version' => 1], $verify->fetchAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]));
        self::assertSame([12000], $verify->fetchFirstColumn('SELECT gross_amount FROM pricing_price_history WHERE variant_id = ?', [$variantId]));
        $verify->close();
    }

    /**
     * A handler as one request: its own entity manager and connection, inside a transaction like the
     * doctrine_transaction middleware of command.bus.
     *
     * @param (\Closure(): void)|null $afterRead the competing request, run after this one has read the price
     *
     * @return \Closure(SetVariantPrice): void
     */
    private function handler(EntityManagerInterface $entityManager, string $variantId, ?\Closure $afterRead = null): \Closure
    {
        $handler = new SetVariantPriceHandler(
            new InterleavingVariantPriceRepository(new DoctrineVariantPriceRepository($entityManager, new RecordingDomainEventPublisher()), $afterRead),
            new InMemoryVariantTaxClasses([$variantId => 'standard']),
            new PolishVatRates(),
        );

        return static function (SetVariantPrice $command) use ($entityManager, $handler): void {
            $entityManager->wrapInTransaction(static fn () => $handler($command));
        };
    }

    private function dispatch(SetVariantPrice $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
    }

    private function catalogVariant(TaxClassCode $taxClass): string
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => $taxClass]);
        new DoctrineProductRepository($entityManager)->save($product);

        return $product->variants()->toList()[0]->id()->toString();
    }
}
