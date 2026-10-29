<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use PHPUnit\Framework\TestCase;

/**
 * Tree R > C > L and a separate root X. "Żel" is in L with the additional category X, "buty" in C, "Ajax" in X.
 */
final class ProductSearchTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const string R = '00000000-0000-4000-8000-0000000000a1';
    private const string C = '00000000-0000-4000-8000-0000000000a2';
    private const string L = '00000000-0000-4000-8000-0000000000a3';
    private const string X = '00000000-0000-4000-8000-0000000000b1';

    protected function setUp(): void
    {
        $this->maintenance()->setup();
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
        $indexer->index(self::document('0198a1f0-0000-7000-8000-000000000001', 'Żel', self::L, [self::X], [self::R, self::C, self::L, self::X]));
        $indexer->index(self::document('0198a1f0-0000-7000-8000-000000000002', 'buty', self::C, [], [self::R, self::C]));
        $indexer->index(self::document('0198a1f0-0000-7000-8000-000000000003', 'Ajax', self::X, [], [self::X]));
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    public function testCategoryFilterIncludesDescendantsAndAdditionalCategories(): void
    {
        self::assertSame(['buty', 'Żel'], self::names($this->search()->search(self::R, 1, 10)));
        self::assertSame(['Żel'], self::names($this->search()->search(self::L, 1, 10)));
        self::assertSame(['Ajax', 'Żel'], self::names($this->search()->search(self::X, 1, 10)));
        self::assertSame(2, $this->search()->search(self::X, 1, 10)->total);
        self::assertSame(3, $this->search()->search(null, 1, 10)->total);
    }

    public function testSortsByNameIgnoringCaseAndDiacritics(): void
    {
        self::assertSame(['Ajax', 'buty', 'Żel'], self::names($this->search()->search(null, 1, 10)));
    }

    public function testReturnsWholeDocuments(): void
    {
        $items = $this->search()->search(self::L, 1, 10)->items;

        self::assertEquals([self::document('0198a1f0-0000-7000-8000-000000000001', 'Żel', self::L, [self::X], [self::R, self::C, self::L, self::X])], $items);
    }

    public function testPaginates(): void
    {
        $second = $this->search()->search(null, 2, 2);
        self::assertSame(['Żel'], self::names($second));
        self::assertSame(3, $second->total);

        $beyond = $this->search()->search(null, 99, 2);
        self::assertSame([], $beyond->items);
        self::assertSame(3, $beyond->total);
    }

    public function testFromAtTheEndOfTheResultWindowGivesAnEmptyPageWithTheRealTotal(): void
    {
        $search = $this->searchWithResultWindow(2);

        $atEnd = $search->search(null, 2, 2);
        self::assertSame([], $atEnd->items);
        self::assertSame(3, $atEnd->total);

        $pastEnd = $search->search(null, 3, 2);
        self::assertSame([], $pastEnd->items);
        self::assertSame(3, $pastEnd->total);
    }

    public function testPageCrossingTheResultWindowIsCutAtItsEnd(): void
    {
        $result = $this->searchWithResultWindow(2)->search(null, 1, 3);

        self::assertSame(['Ajax', 'buty'], self::names($result));
        self::assertSame(3, $result->total);
    }

    public function testPageAndPerPageMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->search()->search(null, 0, 10);
    }

    private function search(): ElasticsearchProductSearch
    {
        return new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias());
    }

    private function searchWithResultWindow(int $window): ElasticsearchProductSearch
    {
        self::esClient()->indices()->putSettings(['index' => $this->alias(), 'body' => ['index' => ['max_result_window' => $window]]]);

        return new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias(), $window);
    }

    /**
     * @return list<string>
     */
    private static function names(SearchResult $result): array
    {
        return array_map(static fn (ProductDocument $d): string => $d->name, $result->items);
    }

    /**
     * @param list<string> $additional
     * @param list<string> $ancestors
     */
    private static function document(string $id, string $name, string $main, array $additional, array $ancestors): ProductDocument
    {
        return new ProductDocument($id, 1, $name, 'slug-'.$id, 'Short', $main, [$main, ...$additional], $ancestors, null);
    }
}
