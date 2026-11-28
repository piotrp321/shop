<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The requests of a listing to Elasticsearch (spec search, M2, "Zapytanie", "Fasety", "Scenariusze błędów"): one
 * `_msearch` with the page of results and the facets grouped by their conditions, more `_msearch` only for a group with
 * more distinct tokens than the size of `terms`, and an error of any part is the error of the whole listing.
 */
final class ListingRequestTest extends TestCase
{
    use UsesTemporarySearchAlias;

    protected function setUp(): void
    {
        $this->maintenance()->setup();
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(10.0), $this->alias());
        foreach (EdgeCaseCatalog::products() as $document) {
            $indexer->index($document);
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    public function testAListingIsOneMultiSearchWithThePageAndFacetSearchesOfSizeZero(): void
    {
        $http = $this->recording();

        $this->search($http)->search(new ProductSearchCriteria(1, 4, facetCategoryIds: EdgeCaseCatalog::facetCategories(null)));

        self::assertSame(['POST /'.$this->alias().'/_msearch'], $http->paths());
        [$searches] = $http->multiSearches();
        [$page, $facets, $lowest, $highest] = $searches;
        self::assertCount(4, $searches);
        self::assertSame(4, $page['size']);
        self::assertArrayNotHasKey('aggs', $page);
        self::assertArrayNotHasKey('post_filter', $page);
        // Categories, axes, values and availability from one terms aggregation over the tokens.
        self::assertSame(0, $facets['size']);
        self::assertSame(['tokens'], array_keys(self::map($facets['aggs'])));
        // The price range without options: the lowest "from" price and the highest price of a buyable product.
        self::assertSame([1, [['from_gross' => 'asc']]], [$lowest['size'], $lowest['sort']]);
        self::assertSame([1, [['max_gross' => 'desc']]], [$highest['size'], $highest['sort']]);
    }

    public function testTheFacetsOfASelectedAxisAloneAreTheSearchOfTheListingWithoutOptions(): void
    {
        $withoutOptions = $this->recording();
        $this->search($withoutOptions)->search(new ProductSearchCriteria(1, 4, facetCategoryIds: EdgeCaseCatalog::facetCategories(null)));
        $withAnAxis = $this->recording();

        $this->search($withAnAxis)->search(new ProductSearchCriteria(1, 4, options: [EdgeCaseCatalog::KOLOR => ['czarny']], facetCategoryIds: EdgeCaseCatalog::facetCategories(null)));

        self::assertSame(['POST /'.$this->alias().'/_msearch'], $withAnAxis->paths());
        [$searches] = $withAnAxis->multiSearches();
        self::assertArrayNotHasKey('aggs', $searches[0]);
        foreach (\array_slice($searches, 1) as $facets) {
            self::assertSame(0, $facets['size']);
        }
        // The same request, so the same entry of the request cache.
        self::assertContains($withoutOptions->multiSearches()[0][1], \array_slice($searches, 1));
    }

    public function testAListingWithAPhraseMatchesTheTextOnce(): void
    {
        $http = $this->recording();

        $this->search($http)->search(new ProductSearchCriteria(1, 4, phrase: EdgeCaseCatalog::PHRASE, options: [EdgeCaseCatalog::KOLOR => ['czarny']], facetCategoryIds: EdgeCaseCatalog::facetCategories(null)));

        self::assertSame(['POST /'.$this->alias().'/_msearch'], $http->paths());
        [$searches] = $http->multiSearches();
        self::assertCount(1, $searches);
        self::assertSame(4, $searches[0]['size']);
        self::assertArrayHasKey('aggs', $searches[0]);
    }

    /**
     * @return iterable<string, array{ProductSearchCriteria}>
     */
    public static function listingsWithEveryKindOfGroup(): iterable
    {
        $categories = EdgeCaseCatalog::facetCategories(null);
        yield 'without conditions' => [new ProductSearchCriteria(1, 4, facetCategoryIds: $categories)];
        yield 'one value of the hub axis' => [new ProductSearchCriteria(1, 4, options: [EdgeCaseCatalog::HUB => ['h']], facetCategoryIds: $categories)];
        yield 'a price range' => [new ProductSearchCriteria(1, 4, priceMin: 2000, priceMax: 6000, facetCategoryIds: $categories)];
        yield 'a phrase' => [new ProductSearchCriteria(1, 4, phrase: EdgeCaseCatalog::PHRASE, facetCategoryIds: $categories)];
    }

    /**
     * Every group of the listing has more tokens than a terms aggregation of size 1 returns: the axes (with the
     * categories and the availability) and then the values of the axes are two more multi searches.
     */
    #[DataProvider('listingsWithEveryKindOfGroup')]
    public function testAGroupWithMoreTokensThanTheSizeOfTermsIsCountedByTwoMoreMultiSearches(ProductSearchCriteria $criteria): void
    {
        $exact = $this->recording();
        $overflowing = $this->recording();

        $expected = $this->search($exact)->search($criteria);
        $result = $this->search($overflowing, termsSize: 1)->search($criteria);

        self::assertSame(['POST /'.$this->alias().'/_msearch'], $exact->paths());
        self::assertSame(array_fill(0, 3, 'POST /'.$this->alias().'/_msearch'), $overflowing->paths());
        self::assertEquals($expected, $result);
    }

    /**
     * @return iterable<string, array{int, int, int, string, class-string<\Throwable>}>
     */
    public static function failedParts(): iterable
    {
        foreach (['page' => 0, 'facets' => 1, 'price' => 3] as $name => $part) {
            yield $name.', rejected' => [1, $part, 429, 'es_rejected_execution_exception', SearchUnavailable::class];
            yield $name.', unavailable' => [1, $part, 503, 'unavailable_shards_exception', SearchUnavailable::class];
            yield $name.', refused' => [1, $part, 400, 'search_phase_execution_exception', SearchIndexError::class];
        }
        yield 'axes of an overflow' => [2, 0, 429, 'es_rejected_execution_exception', SearchUnavailable::class];
        yield 'values of an overflow' => [3, 0, 500, 'illegal_state_exception', SearchIndexError::class];
    }

    /**
     * @param class-string<\Throwable> $failure
     */
    #[DataProvider('failedParts')]
    public function testAFailedPartFailsTheWholeListing(int $request, int $part, int $status, string $type, string $failure): void
    {
        $http = new RecordingSearchHttpClient(self::clientFactory()->httpClient(10.0), ['request' => $request, 'part' => $part, 'status' => $status, 'type' => $type]);

        $this->expectException($failure);

        // Without conditions: the page, the facets and two searches of the price; the size of terms makes the facets
        // overflow, so the second and the third multi search are sent.
        $this->search($http, termsSize: 1 === $request ? null : 1)->search(new ProductSearchCriteria(1, 4, facetCategoryIds: EdgeCaseCatalog::facetCategories(null)));
    }

    private function recording(): RecordingSearchHttpClient
    {
        return new RecordingSearchHttpClient(self::clientFactory()->httpClient(10.0));
    }

    /**
     * @param int|null $termsSize the size of a terms aggregation over the tokens, the default of the search without one
     */
    private function search(RecordingSearchHttpClient $http, ?int $termsSize = null): ElasticsearchProductSearch
    {
        $client = self::clientFactory()->createWith($http);

        return null === $termsSize ? new ElasticsearchProductSearch($client, $this->alias()) : new ElasticsearchProductSearch($client, $this->alias(), termsSize: $termsSize);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
