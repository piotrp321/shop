<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\GetProductSummaries;
use App\Catalog\Application\Query\ImageView;
use App\Catalog\Application\Query\InvalidProductSummariesQuery;
use App\Catalog\Application\Query\ProductSummary;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Catalog query Cart asks for the names and images of the products in a cart, on shop_test (rolled back after
 * each test).
 */
final class GetProductSummariesTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
    }

    public function testSummariesOfPresentProductsInTheOrderOfTheRequestWithAbsentOnesLeftOut(): void
    {
        [$withImage, $withoutImage] = $this->saveProducts(['name' => 'Buty Puls', 'imageAlts' => ['Biegaczka', 'Druga']], ['name' => 'Czapka', 'imageAlts' => []]);
        $absent = (string) Uuid::v7();

        $summaries = $this->ask([$withoutImage->id()->toString(), $absent, strtoupper($withImage->id()->toString()), $withoutImage->id()->toString()]);

        self::assertCount(2, $summaries);
        self::assertSame([$withoutImage->id()->toString(), $withImage->id()->toString()], array_map(static fn (ProductSummary $s): string => $s->id, $summaries));
        self::assertSame('Czapka', $summaries[0]->name);
        self::assertNull($summaries[0]->image);
        self::assertSame('Buty Puls', $summaries[1]->name);

        $image = $summaries[1]->image;
        self::assertInstanceOf(ImageView::class, $image);
        self::assertSame('Biegaczka', $image->alt, 'the main image is the first one');
        self::assertSame('listing', $image->size);
        self::assertSame('1:1', $image->aspectRatio);
        self::assertSame(['webp'], array_keys($image->variants));
        self::assertSame([256, 384, 512, 768], array_column($image->variants['webp'], 'width'));
        $urls = self::getContainer()->get(ImageUrlGenerator::class);
        self::assertInstanceOf(ImageUrlGenerator::class, $urls);
        $key = $withImage->images()->main()?->key()->toString();
        self::assertIsString($key);
        self::assertSame($urls->url($key, ImageSize::Listing, 256, ImageFormat::Webp), $image->variants['webp'][0]['url']);
    }

    public function testAHundredIdsAreReadWithOneStatement(): void
    {
        $products = $this->saveProducts(['imageAlts' => ['A']], [], ['imageAlts' => ['C']]);
        $ids = array_map(static fn (Product $p): string => $p->id()->toString(), $products);
        while (\count($ids) < GetProductSummaries::MAX_PRODUCTS) {
            $ids[] = (string) Uuid::v7();
        }
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);

        $before = $counter->count();
        $summaries = $this->ask($ids);

        self::assertSame(1, $counter->count() - $before);
        self::assertCount(3, $summaries);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no id' => [[]];
        yield '101 ids' => [array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 101))];
        yield 'not a uuid' => [['not-a-uuid']];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('invalidQueries')]
    public function testAnInvalidQueryIsRefused(array $ids): void
    {
        $this->expectException(InvalidProductSummariesQuery::class);

        $this->ask($ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ProductSummary>
     */
    private function ask(array $ids): array
    {
        try {
            $result = $this->queryBus->dispatch(new GetProductSummaries($ids))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            throw array_values($failure->getWrappedExceptions())[0];
        }
        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(ProductSummary::class, $result);

        /** @var list<ProductSummary> $result */
        return $result;
    }

    /**
     * @param array<string, mixed> ...$attributes
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
        foreach ($attributes as $one) {
            $product = ProductFactory::createOne(['mainCategory' => $category, ...$one]);
            $repository->save($product);
            $products[] = $product;
        }

        return $products;
    }
}
