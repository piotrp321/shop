<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductDocumentFactory;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Support\SwitchableProductSearch;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Runs on a temporary index of the real Elasticsearch (see UsesTemporarySearchAlias).
 * Category tree (saved in the database): ROOT > CHILD > LEAF, and a separate OTHER. UNKNOWN is never saved.
 */
final class ProductsEndpointTest extends WebTestCase
{
    use Factories;
    use UsesTemporarySearchAlias;

    private const string ROOT = '00000000-0000-4000-8000-0000000000a1';
    private const string CHILD = '00000000-0000-4000-8000-0000000000a2';
    private const string LEAF = '00000000-0000-4000-8000-0000000000a3';
    private const string CHILD_B = '00000000-0000-4000-8000-0000000000a5';
    private const string OTHER = '00000000-0000-4000-8000-0000000000b1';
    private const string EMPTY_CATEGORY = '00000000-0000-4000-8000-0000000000c1';
    private const string UNKNOWN = '00000000-0000-4000-8000-0000000000ff';
    private const string IMAGE_KEY = 'originals/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef.jpg';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The container must survive between requests, the replaced search service lives in it.
        $this->client->disableReboot();
        $this->maintenance()->setup();
        $this->searchService()->use(new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias()));
        $root = $this->saveCategory(self::ROOT, null, 'Bieganie', 'bieganie');
        $child = $this->saveCategory(self::CHILD, $root, 'Buty', 'buty');
        $this->saveCategory(self::LEAF, $child, 'Szosowe', 'szosowe');
        $this->saveCategory(self::CHILD_B, $root, 'Akcesoria', 'akcesoria');
        // After "Bieganie" in the order of the roots, which is by name.
        $this->saveCategory(self::OTHER, null, 'Rower', 'rower');
    }

    public function testResponseHasTheDocumentedShape(): void
    {
        $this->index(
            $this->document('Buty', self::LEAF, [self::ROOT, self::CHILD, self::LEAF], ['key' => self::IMAGE_KEY, 'alt' => 'Biegaczka na leśnej ścieżce']),
            $this->document('Czapka', self::OTHER, [self::OTHER]),
        );

        $body = $this->get('/api/v1/products');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['items', 'page', 'perPage', 'total', 'facets'], array_keys($body));
        self::assertSame(1, $body['page']);
        self::assertSame(24, $body['perPage']);
        self::assertSame(2, $body['total']);

        $items = $this->items($body);
        self::assertSame(['Buty', 'Czapka'], array_column($items, 'name'));
        self::assertSame(['id', 'name', 'slug', 'shortDescription', 'image'], array_keys($items[0]));
        self::assertSame('slug-buty', $items[0]['slug']);
        self::assertNull($items[1]['image']);
        $this->assertKeysMatchSchema($body, 'ProductPage');
        $this->assertKeysMatchSchema($items[0], 'ProductListItem');
        $this->assertKeysMatchSchema($items[1], 'ProductListItem');

        $image = $items[0]['image'];
        self::assertIsArray($image);
        self::assertSame(['alt', 'size', 'aspectRatio', 'variants'], array_keys($image));
        $this->assertKeysMatchSchema($image, 'ProductImage');
        self::assertSame('Biegaczka na leśnej ścieżce', $image['alt']);
        self::assertSame('listing', $image['size']);
        self::assertSame('1:1', $image['aspectRatio']);
        self::assertIsArray($image['variants']);
        self::assertSame(['webp'], array_keys($image['variants']));
        self::assertIsArray($image['variants']['webp']);
        self::assertSame([256, 384, 512, 768], array_column($image['variants']['webp'], 'width'));
        $imgproxy = $_SERVER['IMGPROXY_PUBLIC_URL'] ?? getenv('IMGPROXY_PUBLIC_URL');
        self::assertIsString($imgproxy);
        foreach (array_column($image['variants']['webp'], 'url') as $url) {
            self::assertIsString($url);
            self::assertStringStartsWith(rtrim($imgproxy, '/').'/', $url);
            self::assertStringEndsWith('.webp', $url);
        }
    }

    public function testTopLevelCategoryFilterIncludesProductsOfSubcategories(): void
    {
        $this->index(
            $this->document('Root', self::ROOT, [self::ROOT]),
            $this->document('Child', self::CHILD, [self::ROOT, self::CHILD]),
            $this->document('Leaf', self::LEAF, [self::ROOT, self::CHILD, self::LEAF]),
            $this->document('Other', self::OTHER, [self::OTHER]),
        );

        $body = $this->get('/api/v1/products?category='.self::ROOT);

        self::assertSame(['Child', 'Leaf', 'Root'], array_column($this->items($body), 'name'));
        self::assertSame(3, $body['total']);
        self::assertSame(['Leaf'], array_column($this->items($this->get('/api/v1/products?category='.self::LEAF)), 'name'));
    }

    public function testUnknownCategoryGivesProblemDetailsNotFound(): void
    {
        $this->index($this->document('Buty', self::LEAF, [self::LEAF]));

        $problem = $this->get('/api/v1/products?category='.self::UNKNOWN);

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(404, $problem['status']);
        self::assertSame('Category not found.', $problem['detail']);
        self::assertStringNotContainsString(self::UNKNOWN, (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownCategoryIsDecidedByTheDatabaseNotBySearch(): void
    {
        // The index holds a product of this category id, yet the category is not in the database.
        $this->index($this->document('Buty', self::UNKNOWN, [self::UNKNOWN]));

        $this->get('/api/v1/products?category='.self::UNKNOWN);

        self::assertResponseStatusCodeSame(404);
    }

    public function testExistingCategoryWithoutProductsGivesAnEmptyList(): void
    {
        $this->index($this->document('Buty', self::LEAF, [self::LEAF]));
        $this->saveCategory(self::EMPTY_CATEGORY);

        $body = $this->get('/api/v1/products?category='.self::EMPTY_CATEGORY);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
    }

    public function testPagePastTheEndGivesAnEmptyListWithTheRealTotal(): void
    {
        $this->index($this->document('A', self::LEAF, [self::LEAF]), $this->document('B', self::LEAF, [self::LEAF]), $this->document('C', self::LEAF, [self::LEAF]));

        $body = $this->get('/api/v1/products?page=999');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $body['items']);
        self::assertSame(3, $body['total']);
        self::assertSame(999, $body['page']);
        self::assertSame(24, $body['perPage']);
    }

    public function testPaginates(): void
    {
        $this->index($this->document('A', self::LEAF, [self::LEAF]), $this->document('B', self::LEAF, [self::LEAF]), $this->document('C', self::LEAF, [self::LEAF]));

        $body = $this->get('/api/v1/products?page=2&perPage=2');

        self::assertSame(['C'], array_column($this->items($body), 'name'));
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['perPage']);
        self::assertSame(3, $body['total']);
    }

    public function testPerPageUpTo48IsAccepted(): void
    {
        $this->get('/api/v1/products?perPage=48');

        self::assertResponseStatusCodeSame(200);
    }

    public function testPhraseSearchesTheNameTheCategoryNamesAndTheDescription(): void
    {
        $this->index(
            $this->shoe('Buty szosowe Tarvik Asfalt 200', [], 'Lekkie'),
            $this->shoe('Plecak', [], 'Na asfalt i szutry'),
            $this->shoe('Kurtka', [], 'Opis'),
        );

        $body = $this->get($this->url(['q' => 'asfalt']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Buty szosowe Tarvik Asfalt 200', 'Plecak'], array_column($this->items($body), 'name'));
        self::assertSame(2, $body['total']);
        self::assertSame(['Kurtka'], array_column($this->items($this->get($this->url(['q' => '  kurtki  ']))), 'name'));
        self::assertSame([], $this->items($this->get($this->url(['q' => 'kurtka xyzzy']))));
    }

    public function testOptionsAreSentAsARepeatedKeyAndBelongToOneVariant(): void
    {
        $this->index(...$this->shoes());

        $body = $this->get($this->url(['option' => ['Kolor:czarne', 'Rozmiar:42']]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Buty A', 'Buty C'], array_column($this->items($body), 'name'));
        self::assertSame(2, $body['total']);
        // Black in one variant and 42 in another is not a match.
        self::assertSame(['Buty A', 'Buty B', 'Buty C'], array_column($this->items($this->get($this->url(['option' => ['Kolor:czarne']]))), 'name'));
    }

    public function testSeveralValuesOfOneAxisAndPolishCharactersAndColonsInValues(): void
    {
        $this->index(
            ...$this->shoes(),
            ...[$this->shoe('Skala', [$this->variant(['Skala' => '1:50'], 100, true)])],
        );

        self::assertSame(
            ['Buty A', 'Buty B'],
            array_column($this->items($this->get($this->url(['option' => ['Kolor:białe', 'Kolor:czarne'], 'priceMin' => 9000, 'priceMax' => 15000]))), 'name'),
        );
        self::assertSame(['Skala'], array_column($this->items($this->get($this->url(['option' => ['Skala:1:50']]))), 'name'));
    }

    public function testPriceRangeAndAvailabilityHoldForTheSameVariant(): void
    {
        $this->index(...$this->shoes());

        self::assertSame(['Buty A'], array_column($this->items($this->get($this->url(['option' => ['Kolor:czarne', 'Rozmiar:42'], 'available' => true]))), 'name'));
        self::assertSame(['Buty A'], array_column($this->items($this->get($this->url(['option' => ['Kolor:czarne', 'Rozmiar:42'], 'priceMin' => 9000, 'priceMax' => 11000]))), 'name'));
        self::assertSame(['Buty B'], array_column($this->items($this->get($this->url(['priceMin' => 14000]))), 'name'));
        self::assertSame(['Buty A', 'Buty B'], array_column($this->items($this->get($this->url(['available' => true]))), 'name'));
    }

    public function testSorting(): void
    {
        $this->index(...$this->shoes());

        self::assertSame(['Buty A', 'Buty B', 'Buty C'], array_column($this->items($this->get($this->url(['sort' => 'name']))), 'name'));
        self::assertSame(['Buty A', 'Buty B', 'Buty C'], array_column($this->items($this->get($this->url(['sort' => 'price_asc']))), 'name'));
        self::assertSame(['Buty B', 'Buty A', 'Buty C'], array_column($this->items($this->get($this->url(['sort' => 'price_desc']))), 'name'));
        self::assertSame(['Buty A', 'Buty B', 'Buty C'], array_column($this->items($this->get($this->url(['q' => 'buty', 'sort' => 'relevance']))), 'name'));
    }

    public function testFacetsHaveTheDocumentedShapeAndCounts(): void
    {
        $this->index(...$this->shoes());

        $facets = self::object($this->get($this->url(['option' => ['Kolor:czarne']]))['facets']);

        self::assertSame(['categories', 'options', 'price', 'availableCount'], array_keys($facets));
        $this->assertKeysMatchSchema($facets, 'ProductFacets');
        self::assertSame(
            [['axis' => 'Kolor', 'values' => [['value' => 'czarne', 'count' => 3], ['value' => 'białe', 'count' => 2]]], ['axis' => 'Rozmiar', 'values' => [['value' => '42', 'count' => 2], ['value' => '43', 'count' => 1]]]],
            $facets['options'],
        );
        $axis = self::object(self::list($facets['options'])[0]);
        $this->assertKeysMatchSchema($axis, 'OptionFacet');
        $this->assertKeysMatchSchema(self::object(self::list($axis['values'])[0]), 'OptionValue');
        self::assertSame(['min' => 10000, 'max' => 15000], $facets['price']);
        $this->assertKeysMatchSchema(self::object($facets['price']), 'PriceRange');
        self::assertSame(2, $facets['availableCount']);
    }

    public function testSelectedValueWithoutProductsStaysInTheFacetWithZero(): void
    {
        $this->index(...$this->shoes());

        $body = $this->get($this->url(['option' => ['Kolor:zielone']]));
        $facets = self::object($body['facets']);

        self::assertSame(0, $body['total']);
        self::assertSame([['axis' => 'Kolor', 'values' => [['value' => 'czarne', 'count' => 3], ['value' => 'białe', 'count' => 2], ['value' => 'zielone', 'count' => 0]]]], \array_slice(self::list($facets['options']), 0, 1));
        self::assertNull($facets['price']);
    }

    public function testCategoryFacetListsTheChildrenOfTheFilterCategoryInTreeOrder(): void
    {
        $this->index(
            $this->document('Szosowe', self::LEAF, [self::ROOT, self::CHILD, self::LEAF]),
            $this->document('Trailowe', self::CHILD, [self::ROOT, self::CHILD]),
            $this->document('Bidon', self::CHILD_B, [self::ROOT, self::CHILD_B]),
            $this->document('Czapka', self::OTHER, [self::OTHER]),
        );

        $categories = self::list(self::object($this->get('/api/v1/products?category='.self::ROOT)['facets'])['categories']);

        self::assertSame(
            [
                ['id' => self::CHILD_B, 'name' => 'Akcesoria', 'slug' => 'akcesoria', 'path' => 'bieganie/akcesoria', 'count' => 1],
                ['id' => self::CHILD, 'name' => 'Buty', 'slug' => 'buty', 'path' => 'bieganie/buty', 'count' => 2],
            ],
            $categories,
        );
        $this->assertKeysMatchSchema(self::object($categories[0]), 'CategoryFacet');

        // Without a filter the top-level categories are counted: Bieganie has 3 products, the other root 1.
        $roots = self::list(self::object($this->get('/api/v1/products')['facets'])['categories']);
        self::assertSame(['bieganie', 'bieganie'], [self::object($roots[0])['slug'], self::object($roots[0])['path']]);
        self::assertSame([3], [self::object($roots[0])['count']]);

        self::assertSame([], self::object($this->get('/api/v1/products?category='.self::LEAF)['facets'])['categories']);
    }

    public function testCategoryFacetCountsOnlyProductsWithTheVariantCondition(): void
    {
        $this->index(
            $this->shoe('Czarne w butach', [$this->variant(['Kolor' => 'czarne'], 100, true)], categories: [self::ROOT, self::CHILD]),
            $this->shoe('Białe w butach', [$this->variant(['Kolor' => 'białe'], 100, true)], categories: [self::ROOT, self::CHILD]),
        );

        $categories = self::list(self::object($this->get($this->url(['category' => self::ROOT, 'option' => ['Kolor:czarne']]))['facets'])['categories']);

        self::assertSame([1], array_column($categories, 'count'));
    }

    public function testSearchResponsesKeepTheCacheHeaders(): void
    {
        $this->index(...$this->shoes());

        $body = $this->get($this->url(['q' => 'buty', 'option' => ['Kolor:czarne'], 'available' => true, 'sort' => 'price_desc', 'priceMin' => 0]));

        self::assertResponseStatusCodeSame(200);
        $headers = $this->client->getResponse()->headers;
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
        [$first, $second] = array_column($this->items($body), 'id');
        self::assertIsString($first);
        self::assertIsString($second);
        self::assertSame('catalog product-'.strtolower($first).' product-'.strtolower($second), $headers->get('xkey'));
    }

    public function testUnavailableSearchWithFiltersIsStillA503(): void
    {
        $this->searchService()->use($this->failingSearch(new SearchUnavailable('down')));

        $this->get($this->url(['q' => 'buty', 'option' => ['Kolor:czarne']]));

        self::assertResponseStatusCodeSame(503);
        $this->assertNotStored();
    }

    public function testViolationsOfTheParametersStopTheRequestBeforeTheOptionsAreValidated(): void
    {
        $problem = $this->get('/api/v1/products?option=Kolor&priceMin=-1');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['priceMin'], $this->violationPaths($problem));

        $problem = $this->get('/api/v1/products?option=Kolor');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['option'], $this->violationPaths($problem));
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<string>
     */
    private function violationPaths(array $problem): array
    {
        self::assertIsArray($problem['violations']);
        $paths = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $paths[] = $violation['propertyPath'];
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value) && [] !== $value, 'A JSON object was expected.');

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value), 'A JSON list was expected.');

        return $value;
    }

    /**
     * The query string the way the generated client (openapi-fetch) builds it: an array is a repeated key without
     * `[]`, a value is percent-encoded like encodeURIComponent, `true` is `true`.
     *
     * @param array<string, scalar|list<string>> $parameters
     */
    private function url(array $parameters): string
    {
        $pairs = [];
        foreach ($parameters as $name => $value) {
            foreach (\is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode($name).'='.rawurlencode(true === $item ? 'true' : (string) $item);
            }
        }

        return '/api/v1/products?'.implode('&', $pairs);
    }

    /**
     * @param array<string, string> $options axis => value
     */
    private function variant(array $options, ?int $gross, bool $purchasable): VariantDocument
    {
        $axes = array_map(strval(...), array_keys($options));

        return new VariantDocument($gross, $purchasable, array_map(static fn (string $axis, string $value): string => $axis.VariantDocument::OPTION_SEPARATOR.$value, $axes, $options));
    }

    /**
     * @param list<VariantDocument> $variants
     * @param list<string>          $categories the ancestors of the product's category, the category last
     */
    private function shoe(string $name, array $variants, string $description = 'Opis', array $categories = [self::ROOT, self::CHILD]): ProductDocument
    {
        $fromGross = null;
        foreach ($variants as $variant) {
            if ($variant->purchasable && null !== $variant->gross) {
                $fromGross = min($fromGross ?? $variant->gross, $variant->gross);
            }
        }

        return ProductDocumentFactory::createOne([
            'name' => $name,
            'slug' => 'slug-'.strtolower(str_replace(' ', '-', $name)),
            'shortDescription' => $description,
            'mainCategoryId' => end($categories),
            'categoryIds' => [end($categories)],
            'categoryAncestorIds' => $categories,
            'variants' => $variants,
            'fromGross' => $fromGross,
        ]);
    }

    /**
     * A: black 42 buyable 100 zł, white 43 buyable 120 zł; B: black 43 buyable 150 zł, white 42 not buyable 90 zł;
     * C: black 42 not buyable 200 zł.
     *
     * @return list<ProductDocument>
     */
    private function shoes(): array
    {
        return [
            $this->shoe('Buty A', [$this->variant(['Kolor' => 'czarne', 'Rozmiar' => '42'], 10000, true), $this->variant(['Kolor' => 'białe', 'Rozmiar' => '43'], 12000, true)]),
            $this->shoe('Buty B', [$this->variant(['Kolor' => 'czarne', 'Rozmiar' => '43'], 15000, true), $this->variant(['Kolor' => 'białe', 'Rozmiar' => '42'], 9000, false)]),
            $this->shoe('Buty C', [$this->variant(['Kolor' => 'czarne', 'Rozmiar' => '42'], 20000, false)]),
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'category is not a UUID' => ['category=abc', 'category'];
        yield 'category is empty' => ['category=', 'category'];
        yield 'perPage is zero' => ['perPage=0', 'perPage'];
        yield 'perPage over the maximum' => ['perPage=49', 'perPage'];
        yield 'perPage is negative' => ['perPage=-5', 'perPage'];
        yield 'perPage is not a number' => ['perPage=lots', 'perPage'];
        yield 'page is zero' => ['page=0', 'page'];
        yield 'page is not a number' => ['page=first', 'page'];
        yield 'q is empty' => ['q=', 'q'];
        yield 'q is blank' => ['q=%20%20', 'q'];
        yield 'q is one character' => ['q=a', 'q'];
        yield 'q is 101 characters' => ['q='.str_repeat('a', 101), 'q'];
        yield 'option without a colon' => ['option=Kolor', 'option'];
        yield 'option with an empty axis' => ['option=%3Aczarne', 'option'];
        yield 'option with an empty value' => ['option=Kolor%3A', 'option'];
        yield 'option axis over 30 characters' => ['option='.str_repeat('a', 31).'%3Ax', 'option'];
        yield 'option value over 40 characters' => ['option=Kolor%3A'.str_repeat('a', 41), 'option'];
        yield 'option value with malformed UTF-8' => ['option=Kolor%3A%FF', 'option'];
        yield 'option axis with malformed UTF-8' => ['option=%FF%3Ax', 'option'];
        yield 'option in the bracket form' => ['option%5B%5D=Kolor%3Aczarne', 'option'];
        yield 'over 50 options' => [implode('&', array_map(static fn (int $i): string => 'option=Kolor%3A'.$i, range(1, 51))), 'option'];
        yield 'options of 6 axes' => [implode('&', array_map(static fn (int $i): string => 'option=Os'.$i.'%3Ax', range(1, 6))), 'option'];
        yield 'priceMin is negative' => ['priceMin=-1', 'priceMin'];
        yield 'priceMax is negative' => ['priceMax=-1', 'priceMax'];
        yield 'priceMin is not a number' => ['priceMin=cheap', 'priceMin'];
        yield 'priceMax is a decimal' => ['priceMax=10.5', 'priceMax'];
        yield 'priceMin has a trailing newline' => ['priceMin=10%0A', 'priceMin'];
        yield 'priceMax has a trailing newline' => ['priceMax=10%0A', 'priceMax'];
        yield 'priceMin above priceMax' => ['priceMin=200&priceMax=100', 'priceMin'];
        yield 'available is false' => ['available=false', 'available'];
        yield 'available is a number' => ['available=1', 'available'];
        yield 'available is empty' => ['available=', 'available'];
        yield 'sort is unknown' => ['sort=popular', 'sort'];
        yield 'sort is empty' => ['sort=', 'sort'];
        yield 'relevance without a phrase' => ['sort=relevance', 'sort'];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidParametersGiveProblemDetailsWithViolations(string $query, string $property): void
    {
        $this->index($this->document('Buty', self::LEAF, [self::LEAF]));

        $problem = $this->get('/api/v1/products?'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(400, $problem['status']);
        self::assertIsArray($problem['violations']);
        $violation = $problem['violations'][0];
        self::assertIsArray($violation);
        self::assertSame($property, $violation['propertyPath']);
        self::assertIsString($violation['title']);
        $value = explode('=', explode('&', $query)[0], 2)[1];
        $submitted = rawurldecode($value);
        if (\strlen($submitted) >= 3) {
            self::assertStringNotContainsString($submitted, (string) $this->client->getResponse()->getContent());
        }
        $this->assertNotStored();
    }

    public function testResponseIsPubliclyCachedAndVariesOnOrigin(): void
    {
        $this->get('/api/v1/products');

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertStringContainsString('Origin', (string) $headers->get('Vary'));
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
    }

    public function testResponseIsTaggedWithTheCatalogAndEveryProductOfThePage(): void
    {
        $this->index(
            $this->document('Buty', self::LEAF, [self::LEAF]),
            $this->document('Czapka', self::OTHER, [self::OTHER]),
            $this->document('Kask', self::OTHER, [self::OTHER]),
        );

        $body = $this->get('/api/v1/products?perPage=2');

        self::assertResponseStatusCodeSame(200);
        [$first, $second] = array_column($this->items($body), 'id');
        self::assertIsString($first);
        self::assertIsString($second);
        self::assertSame(
            'catalog product-'.strtolower($first).' product-'.strtolower($second),
            $this->client->getResponse()->headers->get('xkey'),
        );
        self::assertSame('max-age=60, public', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAnEmptyPageIsTaggedWithTheCatalogOnly(): void
    {
        $this->get('/api/v1/products?category='.self::OTHER);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('catalog', $this->client->getResponse()->headers->get('xkey'));
    }

    public function testUnavailableSearchGivesProblemDetailsWithoutTechnicalDetails(): void
    {
        $this->searchService()->use($this->failingSearch(new SearchUnavailable('Elasticsearch at http://elasticsearch:9200 refused the connection.')));

        $problem = $this->get('/api/v1/products');

        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(503, $problem['status']);
        self::assertSame('Service Unavailable', $problem['title']);
        self::assertArrayNotHasKey('detail', $problem);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('elasticsearch', strtolower($content));
        self::assertStringNotContainsString('refused', $content);
        $this->assertNotStored();
    }

    public function testPermanentSearchFailureIsAnOpaque500(): void
    {
        $this->searchService()->use($this->failingSearch(new SearchIndexError('The alias products is missing.')));

        $problem = $this->get('/api/v1/products');

        self::assertResponseStatusCodeSame(500);
        self::assertArrayNotHasKey('detail', $problem);
        self::assertStringNotContainsString('alias', (string) $this->client->getResponse()->getContent());
    }

    public function testWrongMethodReturns405Problem(): void
    {
        $this->client->request('POST', '/api/v1/products');

        self::assertResponseStatusCodeSame(405);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testExportedSpecificationDescribesTheEndpoint(): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);

        self::assertArrayHasKey('/api/v1/products', $spec['paths']);
        self::assertArrayNotHasKey('/health', $spec['paths']);
        self::assertSame([], array_filter(array_keys($spec['paths']), static fn (string $path): bool => str_starts_with($path, '/api/v1/admin')));
        $path = $spec['paths']['/api/v1/products'];
        self::assertIsArray($path);
        $operation = $path['get'];
        self::assertIsArray($operation);
        self::assertIsArray($operation['responses']);
        self::assertEqualsCanonicalizing(['200', '400', '401', '404', '503'], array_keys($operation['responses']));
        foreach (['400', '401', '404', '503'] as $status) {
            $response = $operation['responses'][$status];
            self::assertIsArray($response);
            self::assertIsArray($response['content']);
            self::assertSame(['application/problem+json'], array_keys($response['content']));
            self::assertSame(['schema' => ['$ref' => '#/components/schemas/Problem']], $response['content']['application/problem+json']);
        }
        $ok = $operation['responses']['200'];
        self::assertIsArray($ok);
        self::assertIsArray($ok['content']);
        self::assertSame(['application/json'], array_keys($ok['content']));
        self::assertIsArray($operation['parameters']);
        self::assertEqualsCanonicalizing(['category', 'q', 'option', 'priceMin', 'priceMax', 'available', 'sort', 'page', 'perPage'], array_column($operation['parameters'], 'name'));
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        foreach (['ProductPage', 'ProductListItem', 'ProductImage', 'Problem'] as $schema) {
            self::assertArrayHasKey($schema, $spec['components']['schemas']);
        }
    }

    /**
     * The response object has exactly the properties of the exported schema, and every required one is present.
     *
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);

        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($object), $schemaName.' keys must match the schema.');
        self::assertEqualsCanonicalizing($schema['required'], array_keys($object), $schemaName.' requires every key.');
    }

    /**
     * An error of a catalog read is never kept by a cache, and carries no cache tags.
     */
    private function assertNotStored(): void
    {
        $headers = $this->client->getResponse()->headers;
        $directives = array_map('trim', explode(',', (string) $headers->get('Cache-Control')));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
        self::assertFalse($headers->has('xkey'));
    }

    private function saveCategory(string $id, ?Category $parent = null, ?string $name = null, ?string $slug = null): Category
    {
        $category = CategoryFactory::createOne(['id' => $id, 'parent' => $parent, ...(null === $name ? [] : ['name' => $name]), 'slug' => $slug]);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new DoctrineCategoryRepository($entityManager))->save($category);

        return $category;
    }

    private function searchService(): SwitchableProductSearch
    {
        $service = self::getContainer()->get(SwitchableProductSearch::class);
        self::assertInstanceOf(SwitchableProductSearch::class, $service);

        return $service;
    }

    private function failingSearch(\Throwable $failure): ProductSearch
    {
        return new readonly class($failure) implements ProductSearch {
            public function __construct(private \Throwable $failure)
            {
            }

            public function search(ProductSearchCriteria $criteria): SearchResult
            {
                throw $this->failure;
            }
        };
    }

    /**
     * @param list<string>                         $ancestors
     * @param array{key: string, alt: string}|null $image
     */
    private function document(string $name, string $main, array $ancestors, ?array $image = null): ProductDocument
    {
        return ProductDocumentFactory::createOne([
            'name' => $name,
            'slug' => 'slug-'.strtolower($name),
            'mainCategoryId' => $main,
            'categoryIds' => [$main],
            'categoryAncestorIds' => $ancestors,
            'mainImage' => $image,
        ]);
    }

    private function index(ProductDocument ...$documents): void
    {
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
        foreach ($documents as $document) {
            $indexer->index($document);
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    /**
     * @return array<mixed>
     */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
    }
}
