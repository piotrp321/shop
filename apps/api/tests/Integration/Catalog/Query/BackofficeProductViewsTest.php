<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\BackofficeProductList;
use App\Catalog\Application\Query\BackofficeProductListItem;
use App\Catalog\Application\Query\GetProductEditView;
use App\Catalog\Application\Query\InvalidBackofficeProductSearch;
use App\Catalog\Application\Query\ListBackofficeProducts;
use App\Catalog\Application\Query\ProductEditView;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductStatus;
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
 * The panel's views of the products (list and edit view) on PostgreSQL (shop_test, rolled back after each test).
 */
final class BackofficeProductViewsTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;
    private DoctrineCategoryRepository $categories;
    private DoctrineProductRepository $products;
    private EntityManagerInterface $entityManager;
    private Category $category;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->categories = new DoctrineCategoryRepository($entityManager);
        $this->products = new DoctrineProductRepository($entityManager);
        $this->category = CategoryFactory::createOne(['name' => 'Obuwie']);
        $this->categories->save($this->category);
    }

    public function testListItemCarriesTheSummaryOfTheProduct(): void
    {
        $product = $this->save(['name' => 'Buty', 'slug' => 'buty', 'imageAlts' => ['front', 'tyl'], 'variantAxes' => ['Rozmiar'], 'variants' => [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['40']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['41']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'values' => ['42']],
        ]], new \DateTimeImmutable('2026-11-03T10:15:30+00:00'));

        $list = $this->list();

        self::assertNull($list->next);
        self::assertCount(1, $list->items);
        $item = $list->items[0];
        self::assertSame($product->id()->toString(), $item->id);
        self::assertSame('Buty', $item->name);
        self::assertSame('buty', $item->slug);
        self::assertSame('published', $item->status);
        self::assertSame($this->category->id()->toString(), $item->mainCategory->id);
        self::assertSame('Obuwie', $item->mainCategory->name);
        self::assertSame(3, $item->variantCount);
        self::assertSame('2026-11-03T10:15:30+00:00', $item->updatedAt->format(\DATE_ATOM));
        self::assertNotNull($item->image);
        self::assertSame('front', $item->image->alt);
        self::assertSame('thumbnail', $item->image->size);
        self::assertSame('1:1', $item->image->aspectRatio);
        self::assertSame([96, 192], array_column($item->image->variants['webp'], 'width'));
        self::assertStringStartsWith('http', $item->image->variants['webp'][0]['url']);
    }

    public function testAProductWithoutImagesHasNoImage(): void
    {
        $this->save(['name' => 'Bez zdjęcia']);

        self::assertNull($this->list()->items[0]->image);
    }

    public function testStatusFilterListsOnlyDraftsOrOnlyPublishedProductsWithKeysetPaging(): void
    {
        foreach (['Szkic A', 'Szkic B', 'Szkic C'] as $name) {
            $this->save(['name' => $name, 'status' => ProductStatus::Draft]);
        }
        foreach (['Opublikowany A', 'Opublikowany B'] as $name) {
            $this->save(['name' => $name]);
        }

        $first = $this->list(status: 'draft', perPage: 2);
        self::assertNotNull($first->next);
        $second = $this->list(status: 'draft', after: $first->next, perPage: 2);

        self::assertSame(['Szkic A', 'Szkic B'], $this->names($first));
        self::assertSame(['Szkic C'], $this->names($second));
        self::assertNull($second->next);
        self::assertSame(['draft'], array_values(array_unique(array_map(static fn (BackofficeProductListItem $item): string => $item->status, [...$first->items, ...$second->items]))));
        self::assertSame(['Opublikowany A', 'Opublikowany B'], $this->names($this->list(status: 'published')));
        self::assertCount(5, $this->list()->items);
    }

    public function testStatusCombinesWithTheTermAndTheCategory(): void
    {
        $other = CategoryFactory::createOne();
        $this->categories->save($other);
        $this->save(['name' => 'Namiot szkic', 'status' => ProductStatus::Draft]);
        $this->save(['name' => 'Namiot szkic obcy', 'status' => ProductStatus::Draft, 'mainCategory' => $other]);
        $this->save(['name' => 'Namiot opublikowany']);

        $list = $this->list(term: 'namiot', categoryId: $this->category->id()->toString(), status: 'draft');

        self::assertSame(['Namiot szkic'], $this->names($list));
    }

    public function testTheDraftListUsesThePartialIndexOfDrafts(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET LOCAL enable_seqscan = off');

        $lines = $connection->fetchFirstColumn("EXPLAIN SELECT p.id FROM catalog_product p WHERE p.status = 'draft' AND (p.name, p.id) > ('Alfa', CAST('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60' AS uuid)) ORDER BY p.name, p.id LIMIT 26");

        self::assertStringContainsString('catalog_product_draft_name_id_idx', implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines)));
    }

    public function testAnUnknownStatusIsInvalid(): void
    {
        $this->expectException(InvalidBackofficeProductSearch::class);

        $this->list(status: 'archived');
    }

    public function testEditViewHoldsEverythingTheFormNeeds(): void
    {
        $extra = CategoryFactory::createOne();
        $this->categories->save($extra);
        $product = $this->save([
            'name' => 'Kurtka', 'slug' => 'kurtka', 'shortDescription' => 'Ciepła', 'additionalCategories' => [$extra],
            'imageAlts' => ['przód', 'tył'], 'variantAxes' => ['Kolor'], 'taxClass' => \App\Catalog\Domain\Product\TaxClassCode::EnergyBar,
            'variants' => [['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['czarne']], ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['białe']]],
        ], new \DateTimeImmutable('2026-11-03T10:15:30+00:00'));

        $view = $this->view($product->id()->toString());

        self::assertSame($product->id()->toString(), $view->id);
        self::assertSame(1, $view->version);
        self::assertSame('published', $view->status);
        self::assertNotNull($view->firstPublishedAt);
        self::assertTrue($view->slugLocked);
        self::assertSame(['Kurtka', 'kurtka', 'Ciepła'], [$view->name, $view->slug, $view->shortDescription]);
        self::assertSame($this->category->id()->toString(), $view->mainCategoryId);
        self::assertSame([$extra->id()->toString()], $view->additionalCategoryIds);
        self::assertSame('energy_bar', $view->taxClass);
        self::assertSame(['przód', 'tył'], array_map(static fn ($image): string => $image->alt, $view->images));
        self::assertSame('originals/'.hash('sha256', $product->id()->toString().'0').'.jpg', $view->images[0]->key);
        self::assertSame('thumbnail', $view->images[0]->preview->size);
        self::assertSame(['Kolor'], $view->variantAxes);
        self::assertSame(
            [['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', ['czarne']], ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', ['białe']]],
            array_map(static fn ($variant): array => [$variant->variantId, $variant->values], $view->variants),
        );
        self::assertSame('2026-11-03T10:15:30+00:00', $view->updatedAt->format(\DATE_ATOM));
    }

    public function testEditViewOfADraftIsNotSlugLockedAndHasNoFirstPublication(): void
    {
        $product = $this->save(['status' => ProductStatus::Draft, 'createdAt' => new \DateTimeImmutable()]);

        $view = $this->view($product->id()->toString());

        self::assertSame('draft', $view->status);
        self::assertNull($view->firstPublishedAt);
        self::assertFalse($view->slugLocked);
        self::assertSame([], $view->images);
        self::assertSame([], $view->variantAxes);
        self::assertCount(1, $view->variants);
        self::assertSame([], $view->variants[0]->values);
    }

    public function testEditViewOfAnUnknownProductIsNotFound(): void
    {
        $this->expectException(ProductNotFound::class);

        $this->view('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60');
    }

    public function testEditViewOfSomethingThatIsNoIdIsNotFound(): void
    {
        $this->expectException(ProductNotFound::class);

        $this->view('not-a-uuid');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function save(array $attributes, ?\DateTimeImmutable $at = null): Product
    {
        $product = ProductFactory::createOne(['mainCategory' => $this->category, ...$attributes]);
        $this->products->save($product, null, null, $at ?? new \DateTimeImmutable());

        return $product;
    }

    private function list(?string $term = null, ?string $categoryId = null, ?string $status = null, ?string $after = null, int $perPage = 25): BackofficeProductList
    {
        $list = $this->ask(new ListBackofficeProducts($term, $categoryId, $status, $after, $perPage));
        self::assertInstanceOf(BackofficeProductList::class, $list);

        return $list;
    }

    private function view(string $id): ProductEditView
    {
        $view = $this->ask(new GetProductEditView($id));
        self::assertInstanceOf(ProductEditView::class, $view);

        return $view;
    }

    private function ask(object $query): mixed
    {
        try {
            $stamp = $this->queryBus->dispatch($query)->last(HandledStamp::class);
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
        self::assertNotNull($stamp);

        return $stamp->getResult();
    }

    /**
     * @return list<string>
     */
    private function names(BackofficeProductList $list): array
    {
        return array_map(static fn (BackofficeProductListItem $item): string => $item->name, $list->items);
    }
}
