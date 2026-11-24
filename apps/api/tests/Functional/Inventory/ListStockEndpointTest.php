<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/stock on PostgreSQL (shop_test, rolled back after each test).
 */
final class ListStockEndpointTest extends WebTestCase
{
    use Factories;
    use StockEndpointTestTrait {
        setUp as private setUpClient;
    }

    private Category $category;

    protected function setUp(): void
    {
        $this->setUpClient();
        $this->category = $this->saveCategory();
    }

    public function testListsProductsWithTheirVariantsInPositionOrderWithAndWithoutAStock(): void
    {
        $kayak = $this->saveProduct('Kajak', variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['pomarańczowy']],
        ], axes: ['Kolor']);
        $gel = $this->saveProduct('Żel malinowy');
        $gelVariant = $gel->variants()->toList()[0]->id()->toString();
        StockFixture::stock($this->connection, 10, 4, 3, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01');

        $body = $this->get('');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNull($body['next']);
        $items = $this->items($body);
        self::assertCount(2, $items);
        self::assertSame(['productId' => $kayak->id()->toString(), 'name' => 'Kajak'], array_diff_key($items[0], ['variants' => 1]));
        $variants = $items[0]['variants'];
        self::assertIsArray($variants);
        self::assertIsArray($variants[1]);
        self::assertSame(
            [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'label' => 'zielony', 'stock' => null, 'matchesLevel' => false], ['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'label' => 'pomarańczowy', 'matchesLevel' => false]],
            [$variants[0], array_diff_key($variants[1], ['stock' => 1])],
        );
        $stock = $variants[1]['stock'];
        self::assertIsArray($stock);
        self::assertSame(['physical' => 10, 'reserved' => 4, 'available' => 6, 'level' => 'in_stock', 'version' => 3], array_diff_key($stock, ['updatedAt' => 1]));
        self::assertUtcTimestamp($stock);
        self::assertNotSame($gel->id()->toString(), $gelVariant);
        self::assertSame(
            ['productId' => $gel->id()->toString(), 'name' => 'Żel malinowy', 'variants' => [['variantId' => $gelVariant, 'label' => '', 'stock' => null, 'matchesLevel' => false]]],
            $items[1],
        );
        $this->assertKeysMatchSchema($body, 'StockListResponse');
        $this->assertKeysMatchSchema($items[0], 'StockListItemResponse');
        $this->assertKeysMatchSchema($variants[1], 'StockListVariantResponse');
        $this->assertKeysMatchSchema($stock, 'ListedStockResponse');
    }

    public function testADraftIsListedWithItsVariantsAndStock(): void
    {
        $draft = $this->saveProduct('Szkic kajaka', status: ProductStatus::Draft);
        $variant = $draft->variants()->toList()[0]->id()->toString();
        StockFixture::stock($this->connection, 7, 0, 1, $variant);

        $items = $this->items($this->get(''));

        self::assertSame([$draft->id()->toString()], array_column($items, 'productId'));
        self::assertIsArray($items[0]['variants']);
        self::assertIsArray($items[0]['variants'][0]);
        self::assertSame($variant, $items[0]['variants'][0]['variantId']);
        $stock = $items[0]['variants'][0]['stock'];
        self::assertIsArray($stock);
        self::assertSame(7, $stock['physical']);
    }

    public function testAPageCountsProductsEachWithAllItsVariants(): void
    {
        $this->saveProduct('A kajak', variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['niebieski']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'values' => ['czerwony']],
        ], axes: ['Kolor']);
        $this->saveProduct('B bidon');

        $first = $this->get('perPage=1');
        $second = $this->get('perPage=1&after='.$this->next($first));

        self::assertSame(['A kajak'], array_column($this->items($first), 'name'));
        self::assertIsArray($this->items($first)[0]['variants']);
        self::assertCount(3, $this->items($first)[0]['variants']);
        self::assertSame(['B bidon'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    public function testEmptyCatalogGivesAnEmptyList(): void
    {
        self::assertSame(['items' => [], 'next' => null, 'total' => ['value' => 0, 'capped' => false]], $this->get(''));
        self::assertResponseStatusCodeSame(200);
    }

    public function testDefaultPageHoldsTwentyFiveProductsAndTheCursorLeadsToTheRest(): void
    {
        for ($n = 1; $n <= 26; ++$n) {
            $this->saveProduct(\sprintf('Produkt %02d', $n));
        }

        $first = $this->get('');
        $second = $this->get('after='.$this->next($first));

        self::assertCount(25, $this->items($first));
        self::assertIsString($first['next']);
        self::assertSame(['Produkt 26'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    public function testPagesWithPerPageAndSearchesByNameAndCategory(): void
    {
        $other = $this->saveCategory();
        foreach (['Bidon 1', 'Bidon 2', 'Bidon 3'] as $name) {
            $this->saveProduct($name);
        }
        $this->saveProduct('Bidon obcy', $other);
        $this->saveProduct('Plecak');
        $category = $this->category->id()->toString();

        $first = $this->get('q=bidon&category='.$category.'&perPage=2');
        $second = $this->get('q=bidon&category='.$category.'&perPage=2&after='.$this->next($first));

        self::assertSame(['Bidon 1', 'Bidon 2'], array_column($this->items($first), 'name'));
        self::assertSame(['Bidon 3'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    /**
     * The total counts what the page counts: products, not their variants.
     */
    public function testTheFirstPageHoldsTheTotalOfTheMatchingProductsAndLaterPagesNone(): void
    {
        $other = $this->saveCategory();
        foreach (['Bidon 1', 'Bidon 2'] as $name) {
            $this->saveProduct($name, variants: [
                ['id' => '0192f0c4-7c1a-7c4e-9a6e-'.\sprintf('%012d', crc32($name.'a')), 'values' => ['40']],
                ['id' => '0192f0c4-7c1a-7c4e-9a6e-'.\sprintf('%012d', crc32($name.'b')), 'values' => ['41']],
            ], axes: ['Rozmiar']);
        }
        $this->saveProduct('Bidon szkic', status: ProductStatus::Draft);
        $this->saveProduct('Bidon obcy', $other);
        $this->saveProduct('Plecak');

        $all = $this->get('perPage=2');
        $later = $this->get('perPage=2&after='.$this->next($all));
        $byName = $this->get('q=bidon&perPage=2');
        $byBoth = $this->get('q=bidon&category='.$this->category->id()->toString());
        $none = $this->get('q=nic-takiego');

        self::assertSame(['value' => 5, 'capped' => false], $all['total']);
        self::assertNull($later['total']);
        self::assertSame(['value' => 4, 'capped' => false], $byName['total']);
        self::assertSame(['value' => 3, 'capped' => false], $byBoth['total']);
        self::assertSame(['value' => 0, 'capped' => false], $none['total']);
        $this->assertKeysMatchSchema($all, 'StockListResponse');
        $this->assertKeysMatchSchema($all['total'], 'BoundedCountResponse');
    }

    public function testAProductIdListsThatProductWithAllItsVariantsAndNoOtherAndNoNextPage(): void
    {
        $kayak = $this->saveProduct('Kajak', variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['pomarańczowy']],
        ], axes: ['Kolor']);
        $this->saveProduct('Bidon');
        StockFixture::stock($this->connection, 10, 4, 3, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01');

        $body = $this->get('productId='.$kayak->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertNull($body['next']);
        self::assertSame(['value' => 1, 'capped' => false], $body['total']);
        $items = $this->items($body);
        self::assertSame([$kayak->id()->toString()], array_column($items, 'productId'));
        self::assertSame(['Kajak'], array_column($items, 'name'));
        self::assertIsArray($items[0]['variants']);
        self::assertSame(['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01'], array_column($items[0]['variants'], 'variantId'));
        [$unstocked, $stocked] = $items[0]['variants'];
        self::assertIsArray($unstocked);
        self::assertIsArray($stocked);
        self::assertNull($unstocked['stock']);
        self::assertIsArray($stocked['stock']);
        self::assertSame(6, $stocked['stock']['available']);
        self::assertFalse($stocked['matchesLevel']);
        $this->assertKeysMatchSchema($body, 'StockListResponse');
    }

    public function testAProductIdListsADraft(): void
    {
        $draft = $this->saveProduct('Szkic kajaka', status: ProductStatus::Draft);

        $items = $this->items($this->get('productId='.$draft->id()->toString()));

        self::assertSame([$draft->id()->toString()], array_column($items, 'productId'));
        self::assertIsArray($items[0]['variants']);
        self::assertCount(1, $items[0]['variants']);
    }

    public function testAnUnknownProductIdGivesAnEmptyList(): void
    {
        $this->saveProduct('Bidon');

        $body = $this->get('productId=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a99');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'next' => null, 'total' => ['value' => 0, 'capped' => false]], $body);
    }

    /**
     * @return iterable<string, array{string, string}> the query, the field of the violation
     */
    public static function productIdWithExcludedParameters(): iterable
    {
        yield 'a search' => ['q=kajak', 'q'];
        yield 'an empty search' => ['q=', 'q'];
        yield 'a category' => ['category=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', 'category'];
        yield 'a level' => ['level=out_of_stock', 'level'];
        yield 'a sort by name' => ['sort=name', 'sort'];
        yield 'a sort by the last change' => ['sort=-updatedAt', 'sort'];
        yield 'a cursor' => ['after=aGVsbG8', 'after'];
    }

    #[DataProvider('productIdWithExcludedParameters')]
    public function testAProductIdRejectsTheParametersItExcludes(string $excluded, string $path): void
    {
        $this->get('productId=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a99&'.$excluded);

        $problem = $this->assertProblem(400);
        self::assertContains($path, $this->violationPaths(400));
        self::assertIsArray($problem['violations']);
        $titles = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            if ($path === $violation['propertyPath']) {
                $titles[] = $violation['title'];
            }
        }
        self::assertContains('Filtr produktu nie łączy się z wyszukiwaniem, kategorią, poziomem, sortowaniem ani kursorem.', $titles);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badProductIds(): iterable
    {
        yield 'not a uuid' => ['productId=kajak'];
        yield 'empty' => ['productId='];
        yield 'an array' => ['productId[]=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a99'];
    }

    #[DataProvider('badProductIds')]
    public function testABadProductIdIsRejectedWithProblemDetails(string $query): void
    {
        $this->get($query);

        $this->assertProblem(400);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownSorts(): iterable
    {
        yield 'unknown' => ['sort=zz'];
        yield 'empty' => ['sort='];
        yield 'another letter case' => ['sort=NAME'];
        yield 'an array' => ['sort[]=name'];
        yield 'a keyed array' => ['sort[a]=b'];
    }

    #[DataProvider('unknownSorts')]
    public function testAnUnknownSortIsRefusedWithAPolishMessage(string $query): void
    {
        $problem = $this->get($query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([['sort', 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.']], $this->violationTitles($problem));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badProductIdsInPolish(): iterable
    {
        yield 'not a uuid' => ['productId=kajak'];
        yield 'empty' => ['productId='];
        yield 'an array' => ['productId[]=2c9d1f3e-5b7a-4c8d-9e0f-1a2b3c4d5e6f'];
        yield 'a keyed array' => ['productId[a]=b'];
    }

    #[DataProvider('badProductIdsInPolish')]
    public function testABadProductIdIsRefusedWithAPolishMessage(string $query): void
    {
        $problem = $this->get($query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([['productId', 'Identyfikator produktu musi być poprawnym UUID.']], $this->violationTitles($problem));
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<array{string, string}> the path and the title of every violation
     */
    private function violationTitles(array $problem): array
    {
        self::assertIsArray($problem['violations'] ?? null);
        $titles = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            self::assertIsString($violation['title']);
            $titles[] = [$violation['propertyPath'], $violation['title']];
        }

        return $titles;
    }

    public function testALevelListsExactlyTheProductsWithAVariantAtThatLevelWithAllTheirVariants(): void
    {
        $kayak = $this->saveProduct('Kajak', variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['czerwony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'values' => ['żółty']],
        ], axes: ['Kolor']);
        $bottle = $this->saveProduct('Bidon');
        $tent = $this->saveProduct('Namiot');
        $gel = $this->saveProduct('Żel');
        $orphan = $this->saveProduct('Bez produktu w rekordzie');
        $unrecorded = $this->saveProduct('Bez rekordu');
        $this->record($kayak, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 3, 3);
        $this->record($kayak, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 10, 0);
        $this->record($bottle, $this->variantOf($bottle), 8, 4);
        $this->record($tent, $this->variantOf($tent), 6, 0);
        $this->record($gel, $this->variantOf($gel), 0, 0);
        $this->record(null, $this->variantOf($orphan), 0, 0);
        self::assertNotSame($unrecorded->id()->toString(), $this->variantOf($unrecorded));

        $out = $this->get('level=out_of_stock');
        $low = $this->get('level=low_stock');
        self::assertResponseStatusCodeSame(200);
        $expected = [$kayak->id()->toString(), $gel->id()->toString()];
        sort($expected);
        self::assertSame($expected, array_column($this->items($out), 'productId'));
        self::assertSame([$bottle->id()->toString()], array_column($this->items($low), 'productId'));
        self::assertNull($out['next']);
        self::assertSame(['value' => 2, 'capped' => false], $out['total']);
        self::assertSame(['value' => 1, 'capped' => false], $low['total']);
        $items = $this->items($out);
        $kayakItem = $items[(int) array_search($kayak->id()->toString(), $expected, true)];
        self::assertSame('Kajak', $kayakItem['name']);
        self::assertIsArray($kayakItem['variants']);
        $variants = $kayakItem['variants'];
        self::assertSame(
            [['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'zielony', true], ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'czerwony', false], ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'żółty', false]],
            array_map(static fn (mixed $variant): array => \is_array($variant) ? [$variant['variantId'], $variant['label'], $variant['matchesLevel']] : [], $variants),
        );
        self::assertIsArray($variants[2]);
        self::assertNull($variants[2]['stock']);
        self::assertIsArray($variants[0]);
        $this->assertKeysMatchSchema($variants[0], 'StockListVariantResponse');
    }

    /**
     * @return iterable<string, array{int, int, ?string}> physical, reserved, the level the one variant is listed at
     */
    public static function boundaries(): iterable
    {
        yield 'nothing available' => [5, 5, 'out_of_stock'];
        yield 'one available' => [1, 0, 'low_stock'];
        yield 'five available' => [9, 4, 'low_stock'];
        yield 'six available' => [6, 0, null];
        yield 'reserved beyond a low stock' => [7, 3, 'low_stock'];
    }

    #[DataProvider('boundaries')]
    public function testTheBoundariesOfTheLevelsAreTheAvailableQuantityNotThePhysicalOne(int $physical, int $reserved, ?string $level): void
    {
        $product = $this->saveProduct('Bidon');
        $this->record($product, $this->variantOf($product), $physical, $reserved);

        foreach (['out_of_stock', 'low_stock'] as $candidate) {
            $items = $this->items($this->get('level='.$candidate));

            self::assertSame($candidate === $level ? [$product->id()->toString()] : [], array_column($items, 'productId'), $candidate);
        }
    }

    public function testALevelPagesByProductIdWithItsOwnCursor(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $product = $this->saveProduct('Produkt '.(6 - $n));
            $this->record($product, $this->variantOf($product), 0, 0);
            $ids[] = $product->id()->toString();
        }
        $other = $this->saveProduct('Niski');
        $this->record($other, $this->variantOf($other), 3, 0);
        sort($ids);

        $first = $this->get('level=out_of_stock&perPage=2');
        $second = $this->get('level=out_of_stock&perPage=2&after='.$this->next($first));
        $third = $this->get('level=out_of_stock&perPage=2&after='.$this->next($second));

        self::assertSame(\array_slice($ids, 0, 2), array_column($this->items($first), 'productId'));
        self::assertSame(\array_slice($ids, 2, 2), array_column($this->items($second), 'productId'));
        self::assertSame(\array_slice($ids, 4), array_column($this->items($third), 'productId'));
        self::assertNull($third['next']);
        self::assertSame(['value' => 5, 'capped' => false], $first['total']);
        self::assertNull($second['total']);
        self::assertNull($third['total']);
    }

    public function testALevelListsNoProductWithoutAFindInCatalogAndKeepsPaging(): void
    {
        $gone = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a99';
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at) VALUES (?, ?, 0, 0, 0, now())',
            ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b99', $gone],
        );

        $body = $this->get('level=out_of_stock');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->items($body));
    }

    public function testALevelSeesTheStockChangesAtOnce(): void
    {
        $product = $this->saveProduct('Bidon');
        $variant = $this->variantOf($product);
        $this->record($product, $variant, 4, 0);
        self::assertSame([$product->id()->toString()], array_column($this->items($this->get('level=low_stock')), 'productId'));

        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 40 WHERE variant_id = ?', [$variant]);

        self::assertSame([], $this->items($this->get('level=low_stock')));
    }

    /**
     * @return iterable<string, array{string, string}> the query, the field of the violation
     */
    public static function levelWithExcludedParameters(): iterable
    {
        yield 'a search' => ['level=out_of_stock&q=bidon', 'q'];
        yield 'an empty search' => ['level=low_stock&q=', 'q'];
        yield 'a category' => ['level=low_stock&category=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', 'category'];
        yield 'a sort by name' => ['level=out_of_stock&sort=name', 'sort'];
        yield 'a sort by the last change' => ['level=out_of_stock&sort=-updatedAt', 'sort'];
        yield 'an unknown level' => ['level=in_stock', 'level'];
        yield 'an empty level' => ['level=', 'level'];
        yield 'a level in capitals' => ['level=OUT_OF_STOCK', 'level'];
    }

    #[DataProvider('levelWithExcludedParameters')]
    public function testALevelRejectsTheParametersItExcludesAndAnUnknownLevel(string $query, string $path): void
    {
        $this->saveProduct('Bidon');

        $this->get($query);

        $this->assertProblem(400);
        self::assertContains($path, $this->violationPaths(400));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLevels(): iterable
    {
        yield 'a level of an ample stock' => ['level=in_stock'];
        yield 'capitals' => ['level=OUT_OF_STOCK'];
        yield 'an array' => ['level[]=out_of_stock'];
    }

    #[DataProvider('badLevels')]
    public function testTheViolationOfABadLevelIsInPolishAlsoForAnArray(string $query): void
    {
        $this->get($query);

        $problem = $this->assertProblem(400);
        self::assertSame(['level'], $this->violationPaths(400));
        self::assertIsArray($problem['violations']);
        $violation = $problem['violations'][0];
        self::assertIsArray($violation);
        self::assertSame('Poziom stanu musi być jednym z: out_of_stock, low_stock.', $violation['title']);
    }

    public function testTheViolationOfALevelWithASearchIsInPolish(): void
    {
        $this->get('level=out_of_stock&q=bidon&category=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60&sort=name');

        $problem = $this->assertProblem(400);
        self::assertIsArray($problem['violations']);
        $titles = array_map(static fn (mixed $violation): mixed => \is_array($violation) ? ($violation['title'] ?? null) : null, $problem['violations']);
        self::assertCount(3, $titles);
        foreach ($titles as $title) {
            self::assertSame('Filtr poziomu nie łączy się z wyszukiwaniem, kategorią ani sortowaniem.', $title);
        }
    }

    public function testACursorOfAnotherLevelOrOfTheNameListIsRejected(): void
    {
        foreach (['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61'] as $n => $unused) {
            $product = $this->saveProduct('Produkt '.$n);
            $this->record($product, $this->variantOf($product), 0, 0);
        }
        $byName = $this->get('perPage=1');
        $out = $this->get('level=out_of_stock&perPage=1');
        $low = $this->get('level=low_stock&perPage=1');
        self::assertIsString($out['next']);
        self::assertNull($low['next']);

        $this->get('level=low_stock&after='.$out['next']);
        self::assertContains('after', $this->violationPaths(400));

        $this->get('level=out_of_stock&after='.$this->next($byName));
        self::assertContains('after', $this->violationPaths(400));

        $this->get('after='.$out['next']);
        self::assertContains('after', $this->violationPaths(400));
    }

    public function testAVariantAtTheLevelMatchesOnlyWhenAListedLevelIsAsked(): void
    {
        $product = $this->saveProduct('Bidon');
        $this->record($product, $this->variantOf($product), 0, 0);

        $plain = $this->items($this->get(''));
        $filtered = $this->items($this->get('level=out_of_stock'));

        self::assertIsArray($plain[0]['variants']);
        self::assertIsArray($filtered[0]['variants']);
        self::assertIsArray($plain[0]['variants'][0]);
        self::assertIsArray($filtered[0]['variants'][0]);
        self::assertFalse($plain[0]['variants'][0]['matchesLevel']);
        self::assertTrue($filtered[0]['variants'][0]['matchesLevel']);
    }

    private function variantOf(Product $product): string
    {
        return $product->variants()->toList()[0]->id()->toString();
    }

    /**
     * A stock record of the variant with its product (null as a record written before its product was known).
     */
    private function record(?Product $product, string $variantId, int $physical, int $reserved): void
    {
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at) VALUES (?, ?, ?, ?, 1, now())',
            [$variantId, $product?->id()->toString(), $physical, $reserved],
        );
    }

    public function testResponseIsPrivateAndNotStored(): void
    {
        $this->get('');

        $this->assertPrivateAndNotStored();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'marketing' => [['marketing']];
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testEveryStaffRoleCanReadTheStock(array $roles): void
    {
        $this->saveProduct('Buty');

        $this->get('', TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/admin/stock');

        $this->assertUnauthorized();
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $this->get('', TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedQueries(): iterable
    {
        yield 'one character' => ['q=a'];
        yield 'empty term' => ['q='];
        yield 'term over 100 characters' => ['q='.str_repeat('a', 101)];
        yield 'term with a NUL character' => ['q=a%00zzzz'];
        yield 'term of spaces only' => ['q=%20%20%20'];
        yield 'term of no-break spaces only' => ['q=%C2%A0%C2%A0'];
        yield 'term of zero width spaces only' => ['q=%E2%80%8B%E2%80%8B'];
        yield 'term of hangul fillers only' => ['q=%E3%85%A4%E3%85%A4'];
        yield 'term with a C1 control character' => ['q=a%C2%9Fzzzz'];
        yield 'term with a line separator' => ['q=a%E2%80%A8zzzz'];
        yield 'term with a line separator at the end' => ['q=abcd%E2%80%A8'];
        yield 'term with a tab' => ['q=a%09zzzz'];
        yield 'zero per page' => ['perPage=0'];
        yield 'per page over 100' => ['perPage=101'];
        yield 'negative per page' => ['perPage=-1'];
        yield 'per page not a number' => ['perPage=many'];
        yield 'category not a uuid' => ['category=shoes'];
        yield 'empty category' => ['category='];
        yield 'malformed cursor' => ['after=garbage!'];
        yield 'cursor of another shape' => ['after=aGVsbG8'];
        yield 'empty cursor' => ['after='];
        yield 'term as an array' => ['q[]=abc'];
    }

    #[DataProvider('rejectedQueries')]
    public function testBadParametersAreRejectedWithProblemDetails(string $query): void
    {
        $this->get($query);

        $this->assertProblem(400);
    }

    public function testBoundariesOfTheParametersAreAccepted(): void
    {
        $this->saveProduct('Buty');

        foreach (['q=Bu', 'q='.str_repeat('a', 100), 'perPage=1', 'perPage=100'] as $query) {
            $this->get($query);

            self::assertResponseStatusCodeSame(200, $query);
        }
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        $this->get('category=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60');

        $this->assertProblem(404);
    }

    private function saveCategory(): Category
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);

        return $category;
    }

    /**
     * @param list<array{id: string, values: list<string>}> $variants none for the factory's default variant
     * @param list<string>                                  $axes
     */
    private function saveProduct(string $name, ?Category $category = null, array $variants = [], array $axes = [], ProductStatus $status = ProductStatus::Published): Product
    {
        $attributes = ['mainCategory' => $category ?? $this->category, 'name' => $name, 'status' => $status];
        if ([] !== $variants) {
            $attributes += ['variantAxes' => $axes, 'variants' => $variants];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product;
    }

    /**
     * Requests the list as a staff member (an administrator unless the token says otherwise).
     *
     * @return array<mixed>
     */
    private function get(string $query, ?string $token = null): array
    {
        $this->client->request('GET', '/api/v1/admin/stock'.('' === $query ? '' : '?'.$query), server: ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? TestTokenFactory::staff(['admin']))]);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $body
     */
    private function next(array $body): string
    {
        self::assertIsString($body['next']);

        return $body['next'];
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items']);
        self::assertTrue(array_is_list($body['items']));

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
    }
}
