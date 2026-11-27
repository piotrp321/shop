<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use PHPUnit\Framework\TestCase;

/**
 * The whole listing (total, first page, category, option, price and availability facets) of the real Elasticsearch
 * against the model of the spec computed in PHP ({@see ListingOracle}) on the catalog of edge cases
 * ({@see EdgeCaseCatalog}), for every criterion of the catalog (spec search, "Testy", "Zgodność modelu R", criterion 10).
 */
final class ListingEquivalenceTest extends TestCase
{
    use UsesTemporarySearchAlias;

    /** How many differences the message of a failure shows. */
    private const int SHOWN = 3;

    protected function setUp(): void
    {
        $this->maintenance()->setup();
    }

    public function testTheCatalogHasTheEdgeCasesOfTheOverflow(): void
    {
        $oracle = new ListingOracle(EdgeCaseCatalog::products(), EdgeCaseCatalog::matchesPhrase(...));

        $all = $oracle->listing(new ProductSearchCriteria(1, 24, categoryId: EdgeCaseCatalog::DUZE));
        $axes = array_column($all['options'], 0);
        // Eleven axes beyond the hub: a tie at the tenth.
        self::assertCount(10, $axes);
        self::assertSame(['O09', 'O10'], \array_slice($axes, 8));
        $long = array_column(array_column($all['options'], 1, 0)[EdgeCaseCatalog::DLUGOSC], 0);
        // 34 values, the thirtieth ties with the thirty-first; the value on most variants is not among them.
        self::assertCount(30, $long);
        self::assertSame('d30', $long[29]);
        self::assertNotContains('d34', $long);

        $hub = $oracle->listing(new ProductSearchCriteria(1, 24, options: [EdgeCaseCatalog::HUB => ['h']]));
        // The selected hub takes no place of an axis without selection: ten partners besides it, O12 cut by the tie.
        self::assertCount(11, $hub['options']);
        self::assertNotContains('O12', array_column($hub['options'], 0));

        $selected = $oracle->listing(new ProductSearchCriteria(1, 24, options: [EdgeCaseCatalog::DLUGOSC => ['d33', 'd99']]));
        $values = array_column($selected['options'], 1, 0)[EdgeCaseCatalog::DLUGOSC];
        // The selected values beyond the thirtieth and without products come after the thirty.
        self::assertSame([['d33', 1], ['d99', 0]], \array_slice($values, 30));
    }

    public function testTheListingEqualsTheModelOfTheSpecForEveryCriterion(): void
    {
        $documents = EdgeCaseCatalog::products();
        $this->index($documents);

        $this->assertEveryCriterionEqualsTheOracle($documents);
    }

    public function testTheListingEqualsTheModelOfTheSpecAfterChangesOfExistingProducts(): void
    {
        $this->index(EdgeCaseCatalog::products());
        $changed = EdgeCaseCatalog::changed();
        $this->index($changed);

        $byId = [];
        foreach ([...EdgeCaseCatalog::products(), ...$changed] as $document) {
            $byId[$document->id] = $document;
        }
        $this->assertEveryCriterionEqualsTheOracle(array_values($byId));
    }

    /**
     * @param list<ProductDocument> $documents the documents in the index
     */
    private function assertEveryCriterionEqualsTheOracle(array $documents): void
    {
        $oracle = new ListingOracle($documents, EdgeCaseCatalog::matchesPhrase(...));
        $search = new ElasticsearchProductSearch(self::clientFactory()->create(10.0), $this->alias());

        $criteria = EdgeCaseCatalog::criteria();
        $differences = [];
        foreach ($criteria as $label => $one) {
            $expected = $oracle->listing($one);
            $actual = ListingOracle::shape($search->search($one));
            if ($expected !== $actual) {
                $differences[] = ['criteria' => $label, 'expected' => $expected, 'actual' => $actual];
            }
        }

        self::assertSame([], \array_slice($differences, 0, self::SHOWN), \sprintf('%d of %d criteria differ from the model of the spec.', \count($differences), \count($criteria)));
    }

    /**
     * @param list<ProductDocument> $documents
     */
    private function index(array $documents): void
    {
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(10.0), $this->alias());
        foreach ($documents as $document) {
            $indexer->index($document);
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }
}
