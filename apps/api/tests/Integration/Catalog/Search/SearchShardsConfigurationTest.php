<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A product index always has one primary shard (spec: docs/features/search/spec.md, M3 and "Infrastruktura"): the exact
 * facets of the search model and the ranking need it. No variable of the environment changes it, whatever the value
 * and whichever of compose, .env or the chart brings it.
 */
final class SearchShardsConfigurationTest extends KernelTestCase
{
    private const string VARIABLE = 'SEARCH_SHARDS';

    private string|false $before = false;

    protected function setUp(): void
    {
        $this->before = getenv(self::VARIABLE);
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);
        false === $this->before ? putenv(self::VARIABLE) : putenv(self::VARIABLE.'='.$this->before);

        parent::tearDown();
    }

    public function testAnIndexHasOneShardWithoutTheVariable(): void
    {
        unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);
        putenv(self::VARIABLE);

        self::assertSame(1, $this->shardsOfTheNewIndex());
    }

    #[DataProvider('valuesOfTheVariable')]
    public function testNoValueOfTheVariableChangesTheShardsOfTheNewIndex(string $value): void
    {
        $_SERVER[self::VARIABLE] = $value;

        self::assertSame(1, $this->shardsOfTheNewIndex());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesOfTheVariable(): iterable
    {
        yield 'two' => ['2'];
        yield 'four' => ['4'];
        yield 'one' => ['1'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'empty' => [''];
        yield 'not a number' => ['many'];
    }

    private function shardsOfTheNewIndex(): mixed
    {
        $definition = self::getContainer()->get(ProductIndexDefinition::class);
        self::assertInstanceOf(ProductIndexDefinition::class, $definition);

        return $definition->body()['settings']['number_of_shards'];
    }
}
