<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchSearchIndexMaintenance;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\TestCase;

/**
 * Bulk writes to a real Elasticsearch through a transport that injects the faults of an overloaded cluster.
 */
final class SearchIndexWriteRetryTest extends TestCase
{
    use UsesTemporarySearchAlias;

    /** @var list<int> milliseconds the writes waited between attempts */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->maintenance()->setup();
    }

    public function testRetriesOnlyTheItemsRejectedTransientlyAndIndexesEveryDocument(): void
    {
        $http = $this->faults([['transient' => [1, 3]], ['transient' => [1]]]);
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();

        $result = $maintenance->write($index, self::documents(5));

        self::assertTrue($result->allIndexed(), $result->describeProblems());
        self::assertEqualsCanonicalizing(array_map(static fn (ProductDocument $d): string => $d->id, self::documents(5)), $result->indexed);
        // The first request carries all 5 items, the next ones only what was rejected before.
        self::assertSame([3, 1, 1], $http->forwardedItems);
        self::assertSame([1000, 2000], $this->sleeps);
        $maintenance->verify($index, 5);
    }

    public function testRetriesRequestsThatFailAsAWhole(): void
    {
        $http = $this->faults(['unavailable', 'timeout']);
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();

        $result = $maintenance->write($index, self::documents(3));

        self::assertTrue($result->allIndexed(), $result->describeProblems());
        self::assertSame(3, $http->bulkRequests);
        self::assertSame([1000, 2000], $this->sleeps);
        $maintenance->verify($index, 3);
    }

    public function testItemsThatStayTransientAfterFiveAttemptsAreReported(): void
    {
        $http = $this->faults(array_fill(0, 5, ['transient' => [0, 1]]));
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();

        $result = $maintenance->write($index, self::documents(3));

        self::assertSame(5, $http->bulkRequests);
        self::assertSame([1000, 2000, 4000, 8000], $this->sleeps);
        self::assertCount(2, $result->transientFailures);
        self::assertCount(1, $result->indexed);
        self::assertSame([], $result->permanentFailures);
    }

    public function testARequestThatFailsAsAWholeFiveTimesThrowsUnavailable(): void
    {
        $http = $this->faults(['unavailable', 'timeout', 'unavailable', 'timeout', 'unavailable', 'unavailable']);
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();

        try {
            $maintenance->write($index, self::documents(2));
            self::fail('The write did not fail.');
        } catch (SearchUnavailable $e) {
            self::assertStringContainsString('5 attempts', $e->getMessage());
        }

        self::assertSame(5, $http->bulkRequests);
        self::assertSame([1000, 2000, 4000, 8000], $this->sleeps);
    }

    public function testPermanentItemErrorsAreNotRetried(): void
    {
        $http = $this->faults([['permanent' => [0], 'transient' => [1]]]);
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();

        $result = $maintenance->write($index, self::documents(3));

        self::assertSame(1, $http->bulkRequests);
        self::assertSame([], $this->sleeps);
        self::assertCount(1, $result->permanentFailures);
        self::assertCount(1, $result->transientFailures);
        self::assertCount(1, $result->indexed);
    }

    public function testStaleItemsAreNotRetried(): void
    {
        $http = $this->faults([]);
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();
        $maintenance->write($index, [self::document('0198a1f0-0000-7000-8000-000000000001', 5)]);

        $result = $maintenance->write($index, [self::document('0198a1f0-0000-7000-8000-000000000001', 3)]);

        self::assertSame(['0198a1f0-0000-7000-8000-000000000001'], $result->stale);
        self::assertSame(2, $http->bulkRequests);
        self::assertSame([], $this->sleeps);
    }

    public function testAStaleItemIsReportedAndTheTransientOnesOfTheSameRequestAreStillRetried(): void
    {
        // A single write through the rebuild alias was newer than the batch: that item is correct as it is (409).
        $http = $this->faults([[], ['transient' => [2]]]);
        $maintenance = $this->maintenanceWith($http);
        $index = $maintenance->createIndex();
        $maintenance->write($index, [self::document('0198a1f0-0000-7000-8000-000000000001', 5)]);

        $result = $maintenance->write($index, [
            self::document('0198a1f0-0000-7000-8000-000000000001', 3),
            self::document('0198a1f0-0000-7000-8000-000000000002', 3),
            self::document('0198a1f0-0000-7000-8000-000000000003', 3),
        ]);

        self::assertSame(['0198a1f0-0000-7000-8000-000000000001'], $result->stale);
        self::assertSame(['0198a1f0-0000-7000-8000-000000000002', '0198a1f0-0000-7000-8000-000000000003'], $result->indexed);
        self::assertSame([], $result->transientFailures);
        self::assertSame([], $result->permanentFailures);
        self::assertSame(3, $http->bulkRequests);
        self::assertSame([1, 2, 1], $http->forwardedItems);
        self::assertSame([1000], $this->sleeps);
    }

    public function testAnIndexCreatedForARebuildIsFilledWithoutRefreshingAndGetsTheIntervalOfTheDefinitionOnVerify(): void
    {
        $maintenance = $this->maintenance(definition: new ProductIndexDefinition(1, 0, '5s'));
        $index = $maintenance->createIndex();
        $maintenance->write($index, self::documents(2));

        self::assertSame('-1', $this->refreshInterval($index));

        $maintenance->verify($index, 2);

        self::assertSame('5s', $this->refreshInterval($index), 'The refresh interval of the configuration was not set.');
    }

    public function testTheIndexOfSetupHasTheRefreshIntervalOfTheDefinition(): void
    {
        self::assertSame('1s', $this->refreshInterval($this->maintenance()->activeIndex()));
    }

    /**
     * @param list<'unavailable'|'timeout'|array{transient?: list<int>, permanent?: list<int>}> $script
     */
    private function faults(array $script): ScriptedBulkFaultsHttpClient
    {
        return new ScriptedBulkFaultsHttpClient(self::clientFactory()->httpClient(30.0), $script);
    }

    private function maintenanceWith(ScriptedBulkFaultsHttpClient $http): ElasticsearchSearchIndexMaintenance
    {
        return $this->maintenance(longHttpClient: $http, sleeper: function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
        });
    }

    /**
     * @return string|null the explicitly set index.refresh_interval, null when it is not set (the default applies)
     */
    private function refreshInterval(string $index): ?string
    {
        $response = self::esClient()->indices()->getSettings(['index' => $index]);
        \assert($response instanceof Elasticsearch);
        $settings = $response->asArray();
        \assert(\is_array($settings[$index]) && \is_array($settings[$index]['settings']) && \is_array($settings[$index]['settings']['index']));
        $value = $settings[$index]['settings']['index']['refresh_interval'] ?? null;
        \assert(null === $value || \is_string($value));

        return $value;
    }

    /**
     * @return list<ProductDocument>
     */
    private static function documents(int $count): array
    {
        $documents = [];
        for ($i = 1; $i <= $count; ++$i) {
            $documents[] = self::document(\sprintf('0198a1f0-0000-7000-8000-%012d', $i), 1);
        }

        return $documents;
    }

    private static function document(string $id, int $version): ProductDocument
    {
        return new ProductDocument($id, $version, 'Name', 'slug-'.$id, '', 'c', 'c', ['c'], ['c'], null, [], [], null, ProductStatus::Published);
    }
}
