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

    public function testTheBodySortsTheIndexByTheCategoryPathAndMapsItWithoutAnIndex(): void
    {
        $body = new ProductIndexDefinition(1, 0)->body();

        self::assertSame('sort_path', $body['settings']['sort.field']);
        self::assertSame('asc', $body['settings']['sort.order']);
        $properties = $body['mappings']['properties'];
        \assert(\is_array($properties));
        self::assertSame(['type' => 'keyword', 'index' => false], $properties['sort_path']);
    }

    public function testTheBodyCarriesTheRefreshIntervalOfTheConfiguration(): void
    {
        self::assertSame('1s', new ProductIndexDefinition(1, 0)->body()['settings']['refresh_interval']);
        self::assertSame('500ms', new ProductIndexDefinition(1, 0, '500ms')->body()['settings']['refresh_interval']);
        self::assertSame('5s', new ProductIndexDefinition(1, 0, '5s')->refreshInterval());
    }

    public function testTheHashIgnoresShardsReplicasAndTheRefreshIntervalButCoversTheSorting(): void
    {
        $hash = new ProductIndexDefinition(1, 0)->hash();

        self::assertSame($hash, new ProductIndexDefinition(3, 2)->hash());
        self::assertSame($hash, new ProductIndexDefinition(1, 0, '30s')->hash());
        self::assertSame($hash, new ProductIndexDefinition(1, 0, '500ms')->hash());
        // The sorting is a static setting of the index, so it is part of what the hash is made of.
        $sortless = hash('sha256', json_encode([self::section('analysis'), self::section('mappings')], \JSON_THROW_ON_ERROR));
        self::assertNotSame($sortless, $hash);
    }

    private static function section(string $method): mixed
    {
        return new \ReflectionMethod(ProductIndexDefinition::class, $method)->invoke(null);
    }

    #[DataProvider('intervalsOutsideTheFormat')]
    public function testARefreshIntervalOutsideTheFormatIsRefusedWithTheNameOfTheVariable(string $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SEARCH_REFRESH_INTERVAL');

        new ProductIndexDefinition(1, 0, $interval);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function intervalsOutsideTheFormat(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['5'];
        yield 'no number' => ['s'];
        yield 'disabled' => ['-1'];
        yield 'zero' => ['0s'];
        yield 'minutes' => ['1m'];
        yield 'fraction' => ['1.5s'];
        yield 'capital unit' => ['1S'];
        yield 'leading space' => [' 1s'];
        yield 'trailing newline' => ["1s\n"];
        yield 'negative' => ['-5s'];
        yield 'two values' => ['1s 1s'];
        yield 'unicode digit' => ["\u{0665}s"];
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
