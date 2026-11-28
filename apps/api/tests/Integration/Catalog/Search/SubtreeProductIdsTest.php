<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchSubtreeProductIds;
use PHPUnit\Framework\TestCase;

/**
 * The products of a category subtree read from a temporary alias of the real Elasticsearch: the documents with the
 * category among category_ancestor_ids (main and additional categories, drafts too), in the order of the ids, a page
 * after the cursor.
 */
final class SubtreeProductIdsTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const string ROOT = 'c0000000-0000-4000-8000-000000000001';
    private const string CHILD = 'c0000000-0000-4000-8000-000000000002';
    private const string OTHER = 'c0000000-0000-4000-8000-000000000003';

    protected function setUp(): void
    {
        $this->maintenance()->setup();
    }

    public function testPagesThroughTheProductsOfTheSubtreeByIdAfterTheCursor(): void
    {
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
        // Main category in the subtree, an additional one in it, a draft, and two outside it.
        $indexer->index(self::document('a0000000-0000-4000-8000-000000000004', self::CHILD, [], [self::ROOT, self::CHILD]));
        $indexer->index(self::document('a0000000-0000-4000-8000-000000000001', self::OTHER, [self::CHILD], [self::OTHER, self::ROOT, self::CHILD]));
        $indexer->index(self::document('a0000000-0000-4000-8000-000000000003', self::ROOT, [], [self::ROOT], ProductStatus::Draft));
        $indexer->index(self::document('a0000000-0000-4000-8000-000000000002', self::OTHER, [], [self::OTHER]));
        $indexer->index(self::document('b0000000-0000-4000-8000-000000000001', self::OTHER, [], [self::OTHER]));
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
        $ids = $this->ids();

        self::assertSame(['a0000000-0000-4000-8000-000000000001', 'a0000000-0000-4000-8000-000000000003'], $ids->after(CategoryId::fromString(self::ROOT), null, 2));
        self::assertSame(['a0000000-0000-4000-8000-000000000004'], $ids->after(CategoryId::fromString(self::ROOT), 'a0000000-0000-4000-8000-000000000003', 2));
        self::assertSame([], $ids->after(CategoryId::fromString(self::ROOT), 'a0000000-0000-4000-8000-000000000004', 2));
        self::assertSame(['a0000000-0000-4000-8000-000000000001', 'a0000000-0000-4000-8000-000000000004'], $ids->after(CategoryId::fromString(self::CHILD), null, 500));
        self::assertSame([], $ids->after(CategoryId::fromString('c0000000-0000-4000-8000-0000000000ff'), null, 500));
    }

    public function testAMissingIndexIsAPermanentFailure(): void
    {
        $this->expectException(SearchIndexError::class);

        new ElasticsearchSubtreeProductIds(self::clientFactory()->create(2.0), $this->alias().'_missing')
            ->after(CategoryId::fromString(self::ROOT), null, 500);
    }

    private function ids(): ElasticsearchSubtreeProductIds
    {
        return new ElasticsearchSubtreeProductIds(self::clientFactory()->create(2.0), $this->alias());
    }

    /**
     * @param list<string> $additional
     * @param list<string> $ancestors
     */
    private static function document(string $id, string $main, array $additional, array $ancestors, ProductStatus $status = ProductStatus::Published): ProductDocument
    {
        return new ProductDocument($id, 1, 'Produkt', 'produkt-'.substr($id, -2), 'Opis', $main, $main, [$main, ...$additional], $ancestors, null, ['Kategoria'], [], null, $status);
    }
}
