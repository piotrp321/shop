<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\BackofficeProductPage;
use App\Catalog\Application\Query\InvalidBackofficeProductSearch;
use App\Catalog\Application\Query\InvalidProductCursor;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Catalog query behind the price list of the panel, on PostgreSQL (shop_test, rolled back after each test).
 */
final class SearchBackofficeProductsTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;
    private EntityManagerInterface $entityManager;
    private DoctrineCategoryRepository $categories;
    private DoctrineProductRepository $products;

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
    }

    public function testListsProductsSortedByNameThenId(): void
    {
        $category = $this->category();
        $this->product($category, 'Zeta', 'standard', '00000000-0000-4000-8000-00000000000a');
        $this->product($category, 'Alfa', 'printed_book', '00000000-0000-4000-8000-00000000000c');
        $this->product($category, 'Alfa', 'energy_gel', '00000000-0000-4000-8000-00000000000b');

        $page = $this->search();

        self::assertSame(
            [
                ['00000000-0000-4000-8000-00000000000b', 'Alfa', 'energy_gel'],
                ['00000000-0000-4000-8000-00000000000c', 'Alfa', 'printed_book'],
                ['00000000-0000-4000-8000-00000000000a', 'Zeta', 'standard'],
            ],
            $this->rows($page),
        );
        self::assertNull($page->next);
    }

    public function testEmptyCatalogGivesAnEmptyPageWithoutNext(): void
    {
        $page = $this->search();

        self::assertSame([], $page->items);
        self::assertNull($page->next);
    }

    public function testSearchesTheNameAsACaseInsensitiveSubstring(): void
    {
        $category = $this->category();
        $this->product($category, 'Buty do biegania Puls');
        $this->product($category, 'Kijki BIEGOWE');
        $this->product($category, 'Plecak');

        self::assertSame(['Buty do biegania Puls'], $this->names($this->search(q: 'biegan')));
        self::assertSame(['Buty do biegania Puls', 'Kijki BIEGOWE'], $this->names($this->search(q: 'IE')));
        self::assertSame(['Plecak'], $this->names($this->search(q: 'plecak')));
        self::assertSame([], $this->names($this->search(q: 'namiot')));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function patternCharacters(): iterable
    {
        yield 'percent is not a wildcard' => ['%%', []];
        yield 'percent is matched literally' => ['0% b', ['100% bawełna']];
        yield 'underscore is not a wildcard' => ['a_', ['Zestaw a_b']];
        yield 'backslash is literal' => ['\\k', ['Plecak \\kółko']];
        yield 'backslash before a wildcard' => ['\\%', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('patternCharacters')]
    public function testPatternCharactersOfTheTermAreMatchedLiterally(string $term, array $expected): void
    {
        $category = $this->category();
        foreach (['100% bawełna', 'Zestaw a_b', 'Zestaw axb', 'Plecak \\kółko', 'Plecak zwykły'] as $name) {
            $this->product($category, $name);
        }

        self::assertSame($expected, $this->names($this->search(q: $term)));
    }

    public function testFiltersByCategoryIncludingSubcategoriesAndAdditionalCategories(): void
    {
        $sport = $this->category('Sport');
        $running = $this->category('Bieganie', $sport);
        $shoes = $this->category('Buty', $running);
        $food = $this->category('Jedzenie');
        $other = $this->category('Inne');

        $this->product($sport, 'In sport root');
        $this->product($running, 'In running');
        $this->product($shoes, 'In shoes');
        $this->product($food, 'Food with extra shoes', additional: [$shoes]);
        $this->product($other, 'Other with extra sport and running', additional: [$sport, $running]);
        $this->product($other, 'Other alone');

        self::assertSame(
            ['Food with extra shoes', 'In running', 'In shoes', 'In sport root', 'Other with extra sport and running'],
            $this->names($this->search(category: $sport)),
        );
        self::assertSame(
            ['Food with extra shoes', 'In running', 'In shoes', 'Other with extra sport and running'],
            $this->names($this->search(category: $running)),
        );
        self::assertSame(['Food with extra shoes', 'In shoes'], $this->names($this->search(category: $shoes)));
        self::assertSame(['Other alone', 'Other with extra sport and running'], $this->names($this->search(category: $other)));
    }

    public function testASiblingWithTheSamePrefixIsNotPartOfTheSubtree(): void
    {
        $parent = $this->category('Parent');
        $child = $this->category('Child', $parent);
        $this->product($child, 'In child');
        $this->product($parent, 'In parent');

        self::assertSame(['In child'], $this->names($this->search(category: $child)));
    }

    public function testAProductInTheMainAndAnAdditionalCategoryOfTheSubtreeAppearsOnce(): void
    {
        $root = $this->category('Root');
        $child = $this->category('Child', $root);
        $this->product($root, 'Twice', additional: [$child]);

        self::assertSame(['Twice'], $this->names($this->search(category: $root)));
    }

    public function testCategoryWithoutProductsGivesAnEmptyPage(): void
    {
        $empty = $this->category('Empty');
        $this->product($this->category('Full'), 'Somewhere');

        self::assertSame([], $this->search(category: $empty)->items);
    }

    public function testUnknownCategoryIsNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        $this->ask(new SearchBackofficeProducts(null, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', null, 25));
    }

    public function testCombinesTheTermAndTheCategory(): void
    {
        $shoes = $this->category('Shoes');
        $bags = $this->category('Bags');
        $this->product($shoes, 'Blue runner');
        $this->product($shoes, 'Red walker');
        $this->product($bags, 'Blue bag');

        self::assertSame(['Blue runner'], $this->names($this->search(q: 'blue', category: $shoes)));
    }

    public function testKeysetPagingVisitsEveryProductOnceInOrderAndEndsWithoutNext(): void
    {
        $category = $this->category();
        $expected = [];
        // Equal names make the id part of the cursor decisive.
        foreach (['Delta', 'Alfa', 'Charlie', 'Alfa', 'Bravo', 'Alfa', 'Echo'] as $name) {
            $expected[] = $this->product($category, $name);
        }
        usort($expected, static fn (Product $a, Product $b): int => [$a->name()->value(), $a->id()->toString()] <=> [$b->name()->value(), $b->id()->toString()]);

        $seen = [];
        $sizes = [];
        $after = null;
        do {
            $page = $this->search(perPage: 3, after: $after);
            $sizes[] = \count($page->items);
            array_push($seen, ...array_map(static fn (BackofficeProduct $item): string => $item->id, $page->items));
            $after = $page->next;
        } while (null !== $after);

        self::assertSame([3, 3, 1], $sizes);
        self::assertSame(array_map(static fn (Product $product): string => $product->id()->toString(), $expected), $seen);
    }

    public function testAPageThatEndsExactlyAtTheLastProductHasNoNext(): void
    {
        $category = $this->category();
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $this->product($category, $name);
        }

        $first = $this->search(perPage: 2);
        $second = $this->search(perPage: 2, after: $first->next);

        self::assertNotNull($first->next);
        self::assertSame(['C', 'D'], $this->names($second));
        self::assertNull($second->next);
    }

    public function testPagingKeepsTheFilters(): void
    {
        $shoes = $this->category('Shoes');
        $bags = $this->category('Bags');
        foreach (['Blue 1', 'Blue 2', 'Blue 3'] as $name) {
            $this->product($shoes, $name);
        }
        $this->product($shoes, 'Red 1');
        $this->product($bags, 'Blue bag');

        $first = $this->search(q: 'blue', category: $shoes, perPage: 2);
        $second = $this->search(q: 'blue', category: $shoes, perPage: 2, after: $first->next);

        self::assertSame(['Blue 1', 'Blue 2'], $this->names($first));
        self::assertSame(['Blue 3'], $this->names($second));
        self::assertNull($second->next);
    }

    public function testNamesWithQuotesAndNonAsciiCharactersSurviveTheCursor(): void
    {
        $category = $this->category();
        foreach (['Żel "energetyczny" \'x\' 1', 'Żel "energetyczny" \'x\' 2', 'Żółw 😀 \\ %'] as $name) {
            $this->product($category, $name);
        }

        $first = $this->search(perPage: 1);
        $second = $this->search(perPage: 1, after: $first->next);
        $third = $this->search(perPage: 1, after: $second->next);

        self::assertSame(3, \count([...$first->items, ...$second->items, ...$third->items]));
        self::assertNull($third->next);
        self::assertSame(
            ['Żel "energetyczny" \'x\' 1', 'Żel "energetyczny" \'x\' 2', 'Żółw 😀 \\ %'],
            array_map(static fn (BackofficeProduct $item): string => $item->name, [...$first->items, ...$second->items, ...$third->items]),
        );
    }

    public function testTheCursorIsOpaqueUrlSafeText(): void
    {
        $category = $this->category();
        $this->product($category, 'Alfa');
        $this->product($category, 'Beta');

        $next = $this->search(perPage: 1)->next;

        self::assertNotNull($next);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $next);
        self::assertStringNotContainsString('Alfa', $next);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64url' => ['***'];
        yield 'plain text' => [rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')];
        yield 'json without the id' => [self::cursor(['n' => 'Alfa'])];
        yield 'json without the name' => [self::cursor(['i' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'])];
        yield 'id not a uuid' => [self::cursor(['n' => 'Alfa', 'i' => "' OR 1=1 --"])];
        yield 'name not a string' => [self::cursor(['n' => 5, 'i' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'])];
        yield 'name too long' => [self::cursor(['n' => str_repeat('a', 201), 'i' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'])];
        yield 'extra key' => [self::cursor(['n' => 'Alfa', 'i' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', 'x' => 1])];
        yield 'json list' => [self::cursor(['Alfa', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'])];
        yield 'json scalar' => [self::cursor('Alfa')];
        yield 'invalid utf-8 in the name' => [rtrim(strtr(base64_encode("{\"n\":\"\xff\",\"i\":\"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60\"}"), '+/', '-_'), '=')];
        yield 'padded base64' => [base64_encode('{"n":"a","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
    }

    #[DataProvider('malformedCursors')]
    public function testMalformedCursorIsRejected(string $cursor): void
    {
        $this->expectException(InvalidProductCursor::class);

        $this->search(after: $cursor);
    }

    /**
     * @return iterable<string, array{?string, ?string, int}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'one character' => ['a', null, 25];
        yield 'empty term' => ['', null, 25];
        yield 'term over 100 characters' => [str_repeat('a', 101), null, 25];
        yield 'zero per page' => [null, null, 0];
        yield 'negative per page' => [null, null, -1];
        yield 'per page over 100' => [null, null, 101];
        yield 'category not a uuid' => [null, 'not-a-uuid', 25];
    }

    #[DataProvider('invalidParameters')]
    public function testParametersOutOfRangeAreRejected(?string $q, ?string $category, int $perPage): void
    {
        $this->expectException(InvalidBackofficeProductSearch::class);

        $this->ask(new SearchBackofficeProducts($q, $category, null, $perPage));
    }

    public function testBoundariesOfTheTermAndOfThePageSizeAreAccepted(): void
    {
        $this->product($this->category(), 'Alfa');

        self::assertSame(['Alfa'], $this->names($this->search(q: 'Al', perPage: 1)));
        self::assertSame([], $this->names($this->search(q: str_repeat('a', 100), perPage: 100)));
    }

    public function testTheTrigramIndexExistsAndSupportsTheNameSearch(): void
    {
        $connection = $this->entityManager->getConnection();
        $definition = $connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'catalog_product' AND indexname = 'catalog_product_name_trgm_idx'");
        self::assertIsString($definition);
        self::assertStringContainsString('gin', $definition);
        self::assertStringContainsString('gin_trgm_ops', $definition);

        $this->product($this->category(), 'Alfa');
        // The other index on the name would also win over a sequential scan; DDL is rolled back with the test.
        $connection->executeStatement('DROP INDEX catalog_product_name_id_idx');
        $plan = $this->planWithoutSequentialScan($connection, "SELECT id FROM catalog_product WHERE name ILIKE '%alf%'");
        self::assertStringContainsString('catalog_product_name_trgm_idx', $plan);
    }

    public function testTheKeysetOrderIsServedByAnIndexOnNameAndId(): void
    {
        $this->product($this->category(), 'Alfa');

        $plan = $this->planWithoutSequentialScan(
            $this->entityManager->getConnection(),
            "SELECT id FROM catalog_product WHERE (name, id) > ('Alfa', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60') ORDER BY name, id LIMIT 26",
        );
        self::assertStringContainsString('catalog_product_name_id_idx', $plan);
    }

    private function planWithoutSequentialScan(Connection $connection, string $sql): string
    {
        $connection->executeStatement('SET LOCAL enable_seqscan = off');
        $lines = $connection->fetchFirstColumn('EXPLAIN '.$sql);

        return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $lines));
    }

    /**
     * @param array<mixed>|string $payload
     */
    private static function cursor(array|string $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function search(?string $q = null, ?Category $category = null, ?string $after = null, int $perPage = 25): BackofficeProductPage
    {
        return $this->ask(new SearchBackofficeProducts($q, $category?->id()->toString(), $after, $perPage));
    }

    private function ask(SearchBackofficeProducts $query): BackofficeProductPage
    {
        try {
            $stamp = $this->queryBus->dispatch($query)->last(HandledStamp::class);
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw.
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
        self::assertNotNull($stamp);
        $page = $stamp->getResult();
        self::assertInstanceOf(BackofficeProductPage::class, $page);

        return $page;
    }

    /**
     * @return list<string>
     */
    private function names(BackofficeProductPage $page): array
    {
        return array_map(static fn (BackofficeProduct $item): string => $item->name, $page->items);
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function rows(BackofficeProductPage $page): array
    {
        return array_map(static fn (BackofficeProduct $item): array => [$item->id, $item->name, $item->taxClass], $page->items);
    }

    private function category(?string $name = null, ?Category $parent = null): Category
    {
        $category = CategoryFactory::createOne(array_filter(['name' => $name, 'parent' => $parent]));
        $this->categories->save($category);

        return $category;
    }

    /**
     * @param list<Category> $additional
     */
    private function product(Category $main, string $name, string $taxClass = 'standard', ?string $id = null, array $additional = []): Product
    {
        $product = ProductFactory::createOne(array_filter([
            'id' => $id,
            'name' => $name,
            'mainCategory' => $main,
            'additionalCategories' => $additional,
            'taxClass' => TaxClassCode::from($taxClass),
        ], static fn (mixed $value): bool => null !== $value));
        $this->products->save($product);

        return $product;
    }
}
