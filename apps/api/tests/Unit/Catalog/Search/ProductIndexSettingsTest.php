<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shards and the replicas of a new product index: they come from the configuration (SEARCH_SHARDS), so a value
 * that Elasticsearch would refuse is refused when the definition is built, with the name of the setting.
 */
final class ProductIndexSettingsTest extends TestCase
{
    public function testTheBodyCarriesTheConfiguredShardsAndReplicas(): void
    {
        $settings = new ProductIndexDefinition(4, 1)->body()['settings'];

        self::assertSame(4, $settings['number_of_shards']);
        self::assertSame(1, $settings['number_of_replicas']);
    }

    #[DataProvider('shardsThatAreNoNumberOfShards')]
    public function testFewerThanOneShardIsRefused(int $shards): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SEARCH_SHARDS');

        new ProductIndexDefinition($shards, 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function shardsThatAreNoNumberOfShards(): iterable
    {
        // An empty SEARCH_SHARDS, or one that is not a number, is refused by the container before the definition is built.
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testNegativeReplicasAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductIndexDefinition(1, -1);
    }
}
