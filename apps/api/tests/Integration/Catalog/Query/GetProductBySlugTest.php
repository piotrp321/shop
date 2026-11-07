<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\BreadcrumbItem;
use App\Catalog\Application\Query\GetProductBySlug;
use App\Catalog\Application\Query\ProductDetails;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * GetProductBySlug on shop_test (rolled back after each test). Every variant id differs from the id of its product.
 */
final class GetProductBySlugTest extends KernelTestCase
{
    use Factories;

    private const string BLACK_42 = 'c0000000-0000-4000-8000-000000000001';
    private const string WHITE_43 = 'a0000000-0000-4000-8000-000000000002';
    private const string BLACK_43 = 'b0000000-0000-4000-8000-000000000003';

    private MessageBusInterface $queryBus;
    private EntityManagerInterface $entityManager;
    /** @var array{shoes: Category, road: Category, other: Category} */
    private array $categories;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = new DoctrineCategoryRepository($this->entityManager);
        $sport = CategoryFactory::createOne(['name' => 'Sport', 'slug' => 'sport']);
        $repository->save($sport);
        $shoes = CategoryFactory::createOne(['name' => 'Obuwie', 'slug' => 'obuwie', 'parent' => $sport]);
        $repository->save($shoes);
        $road = CategoryFactory::createOne(['name' => 'Szosowe', 'slug' => 'szosowe', 'parent' => $shoes]);
        $repository->save($road);
        $other = CategoryFactory::createOne(['name' => 'Akcesoria', 'slug' => 'akcesoria']);
        $repository->save($other);
        $this->categories = ['shoes' => $shoes, 'road' => $road, 'other' => $other];
    }

    public function testBreadcrumbRunsFromTheRootToTheMainCategory(): void
    {
        $this->saveProduct(['slug' => 'buty', 'mainCategory' => $this->categories['road'], 'additionalCategories' => [$this->categories['other']]]);

        $details = $this->details('buty');

        self::assertSame(['Sport', 'Obuwie', 'Szosowe'], array_column($this->breadcrumb($details), 'name'));
        self::assertSame(
            [$this->categories['road']->id()->toString()],
            \array_slice(array_column($this->breadcrumb($details), 'id'), -1),
        );
        // Every element has the slug path of its category, from the root.
        self::assertSame(['sport', 'sport/obuwie', 'sport/obuwie/szosowe'], array_column($this->breadcrumb($details), 'path'));
    }

    public function testBreadcrumbOfARootCategoryIsThatCategory(): void
    {
        $this->saveProduct(['slug' => 'pasek', 'mainCategory' => $this->categories['other']]);

        self::assertSame(['Akcesoria'], array_column($this->breadcrumb($this->details('pasek')), 'name'));
        self::assertSame(['akcesoria'], array_column($this->breadcrumb($this->details('pasek')), 'path'));
    }

    public function testProductFieldsAndImagesInThePositionOrderInTheProductSize(): void
    {
        $product = $this->saveProduct([
            'slug' => 'buty',
            'name' => 'Buty Puls',
            'shortDescription' => 'Lekkie buty.',
            'mainCategory' => $this->categories['road'],
            'imageAlts' => ['Pierwsze', 'Drugie', 'Trzecie'],
        ]);

        $details = $this->details('buty');

        self::assertSame($product->id()->toString(), $details->id);
        self::assertSame(['buty', 'Buty Puls', 'Lekkie buty.'], [$details->slug, $details->name, $details->shortDescription]);
        self::assertSame(['Pierwsze', 'Drugie', 'Trzecie'], array_map(static fn ($image): string => $image->alt, $details->images));
        $urls = self::getContainer()->get(ImageUrlGenerator::class);
        self::assertInstanceOf(ImageUrlGenerator::class, $urls);
        foreach ($details->images as $position => $image) {
            self::assertSame('product', $image->size);
            self::assertSame('1:1', $image->aspectRatio);
            self::assertSame(['webp'], array_keys($image->variants));
            self::assertSame([640, 960, 1280, 1600], array_column($image->variants['webp'], 'width'));
            $key = $product->images()->toList()[$position]->key()->toString();
            self::assertSame(
                $urls->url($key, ImageSize::Product, 640, ImageFormat::Webp),
                $image->variants['webp'][0]['url'],
            );
        }
    }

    public function testProductWithoutImagesHasAnEmptyList(): void
    {
        $this->saveProduct(['slug' => 'pasek', 'mainCategory' => $this->categories['other']]);

        self::assertSame([], $this->details('pasek')->images);
    }

    public function testVariantsInThePositionOrderWithLabelsAndValuesInTheAxisOrder(): void
    {
        $this->saveProduct([
            'slug' => 'buty',
            'mainCategory' => $this->categories['road'],
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [
                ['id' => self::BLACK_42, 'values' => ['czarne', '42']],
                ['id' => self::WHITE_43, 'values' => ['Białe', '43']],
                ['id' => self::BLACK_43, 'values' => ['czarne', '43']],
            ],
        ]);

        $details = $this->details('buty');

        self::assertSame(['Kolor', 'Rozmiar'], $details->variantAxes);
        self::assertSame(
            [
                [self::BLACK_42, 'czarne, 42', ['czarne', '42']],
                [self::WHITE_43, 'Białe, 43', ['Białe', '43']],
                [self::BLACK_43, 'czarne, 43', ['czarne', '43']],
            ],
            array_map(static fn ($variant): array => [$variant->variantId, $variant->label, $variant->values], $details->variants),
        );
    }

    public function testProductWithoutAxesHasTheDefaultVariantWithAnEmptyLabel(): void
    {
        $this->saveProduct(['slug' => 'pasek', 'mainCategory' => $this->categories['other'], 'variants' => [['id' => self::BLACK_42, 'values' => []]]]);

        $details = $this->details('pasek');

        self::assertSame([], $details->variantAxes);
        self::assertCount(1, $details->variants);
        self::assertSame([self::BLACK_42, '', []], [$details->variants[0]->variantId, $details->variants[0]->label, $details->variants[0]->values]);
    }

    public function testUnknownAndMalformedSlugsGiveNull(): void
    {
        $this->saveProduct(['slug' => 'buty', 'mainCategory' => $this->categories['road']]);

        self::assertNull($this->ask('inny-produkt'));
        self::assertNull($this->ask('BUTY'));
        self::assertNull($this->ask('buty/../x'));
        self::assertNull($this->ask(''));
        self::assertNull($this->ask(str_repeat('a', 500)));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function saveProduct(array $attributes): Product
    {
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product;
    }

    private function details(string $slug): ProductDetails
    {
        $details = $this->ask($slug);
        self::assertInstanceOf(ProductDetails::class, $details);

        return $details;
    }

    /**
     * @return list<BreadcrumbItem>
     */
    private function breadcrumb(ProductDetails $details): array
    {
        return $details->breadcrumb;
    }

    private function ask(string $slug): mixed
    {
        return $this->queryBus->dispatch(new GetProductBySlug($slug))->last(HandledStamp::class)?->getResult();
    }
}
