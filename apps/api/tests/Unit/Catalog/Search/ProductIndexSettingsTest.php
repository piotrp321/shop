<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shards and the replicas of a new product index: an index always has one primary shard (the exact facets of the
 * search model need it, spec M3), the replicas and the refresh interval come from the configuration.
 */
final class ProductIndexSettingsTest extends TestCase
{
    public function testTheBodyHasOneShardAndTheConfiguredReplicas(): void
    {
        $settings = new ProductIndexDefinition(1)->body()['settings'];

        self::assertSame(1, $settings['number_of_shards']);
        self::assertSame(1, $settings['number_of_replicas']);
    }

    public function testTheBodySortsTheIndexByTheCategoryPathAndMapsItWithoutAnIndex(): void
    {
        $body = new ProductIndexDefinition(0)->body();

        self::assertSame('sort_path', $body['settings']['sort.field']);
        self::assertSame('asc', $body['settings']['sort.order']);
        $properties = $body['mappings']['properties'];
        \assert(\is_array($properties));
        self::assertSame(['type' => 'keyword', 'index' => false], $properties['sort_path']);
    }

    public function testTheBodyCarriesTheRefreshIntervalOfTheConfiguration(): void
    {
        self::assertSame('1s', new ProductIndexDefinition(0)->body()['settings']['refresh_interval']);
        self::assertSame('500ms', new ProductIndexDefinition(0, '500ms')->body()['settings']['refresh_interval']);
        self::assertSame('5s', new ProductIndexDefinition(0, '5s')->refreshInterval());
    }

    public function testTheHashIgnoresReplicasAndTheRefreshIntervalButCoversTheSorting(): void
    {
        $hash = new ProductIndexDefinition(0)->hash();

        self::assertSame($hash, new ProductIndexDefinition(2)->hash());
        self::assertSame($hash, new ProductIndexDefinition(0, '30s')->hash());
        self::assertSame($hash, new ProductIndexDefinition(0, '500ms')->hash());
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

        new ProductIndexDefinition(0, $interval);
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

    public function testTheVariableOfTheEnvironmentThatIsNotSetOrIsOneIsAccepted(): void
    {
        self::assertSame(1, new ProductIndexDefinition(0, '1s', null)->body()['settings']['number_of_shards']);
        self::assertSame(1, new ProductIndexDefinition(0, '1s', '1')->body()['settings']['number_of_shards']);
    }

    #[DataProvider('shardsOfTheEnvironmentOtherThanOne')]
    public function testAnyOtherValueOfSearchShardsIsRefusedWithTheNameOfTheVariableAndTheReason(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SEARCH_SHARDS.*one shard.*unneeded/s');

        new ProductIndexDefinition(0, '1s', $value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shardsOfTheEnvironmentOtherThanOne(): iterable
    {
        yield 'two' => ['2'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['many'];
        yield 'empty' => [''];
        yield 'leading zero' => ['01'];
        yield 'space' => [' 1'];
        yield 'trailing newline' => ["1\n"];
    }

    public function testNegativeReplicasAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductIndexDefinition(-1);
    }
}
