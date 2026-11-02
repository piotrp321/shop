<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\GetProductTaxClass;
use App\Catalog\Application\Query\ListProductsForPricing;
use App\Catalog\Application\Query\ProductForPricing;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Catalog queries Pricing asks through the query bus, on shop_test (rolled back after each test).
 */
final class ProductsForPricingQueriesTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
    }

    public function testTaxClassOfAProduct(): void
    {
        [$book] = $this->saveProducts(['taxClass' => TaxClassCode::PrintedBook]);

        self::assertSame('printed_book', $this->ask(new GetProductTaxClass($book->id()->toString())));
        self::assertSame('printed_book', $this->ask(new GetProductTaxClass(strtoupper($book->id()->toString()))));
    }

    public function testTaxClassOfAnUnknownProductIsNull(): void
    {
        self::assertNull($this->ask(new GetProductTaxClass('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    public function testTaxClassOfAMalformedIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ask(new GetProductTaxClass('not-a-uuid'));
    }

    public function testListsAllProductsInBatchesOrderedById(): void
    {
        $products = $this->saveProducts(
            ['taxClass' => TaxClassCode::Standard],
            ['taxClass' => TaxClassCode::EnergyGel],
            ['taxClass' => TaxClassCode::PrintedMap],
            ['taxClass' => TaxClassCode::ProteinPowder],
            ['taxClass' => TaxClassCode::Standard],
        );
        $expected = array_map(
            static fn (Product $product): array => [$product->id()->toString(), $product->slug()->value(), $product->taxClass()->value],
            $products,
        );
        usort($expected, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $batches = [];
        $after = null;
        do {
            $batch = $this->list($after, 2);
            $batches[] = $batch;
            $after = [] === $batch ? null : $batch[array_key_last($batch)]->id;
        } while ([] !== $batch);

        self::assertSame([2, 2, 1, 0], array_map('count', $batches));
        self::assertSame($expected, array_map(
            static fn (ProductForPricing $item): array => [$item->id, $item->slug, $item->taxClass],
            array_merge(...$batches),
        ));
    }

    public function testEmptyCatalogGivesAnEmptyBatch(): void
    {
        self::assertSame([], $this->list(null, 10));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above 1000' => [1001];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function testLimitOutsideOneToThousandIsRejected(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->list(null, $limit);
    }

    public function testMalformedCursorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->list("' OR 1=1 --", 10);
    }

    /**
     * @return list<ProductForPricing>
     */
    private function list(?string $after, int $limit): array
    {
        $batch = $this->ask(new ListProductsForPricing($after, $limit));
        self::assertIsArray($batch);

        return array_values(array_filter($batch, static fn (mixed $item): bool => $item instanceof ProductForPricing));
    }

    private function ask(object $query): mixed
    {
        try {
            $stamp = $this->queryBus->dispatch($query)->last(HandledStamp::class);
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw.
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
        self::assertNotNull($stamp);

        return $stamp->getResult();
    }

    /**
     * @param array<string, mixed> ...$attributes one product per argument
     *
     * @return list<Product>
     */
    private function saveProducts(array ...$attributes): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $repository = new DoctrineProductRepository($entityManager);

        $products = [];
        foreach ($attributes as $productAttributes) {
            $product = ProductFactory::createOne(['mainCategory' => $category, ...$productAttributes]);
            $repository->save($product);
            $products[] = $product;
        }

        return $products;
    }
}
