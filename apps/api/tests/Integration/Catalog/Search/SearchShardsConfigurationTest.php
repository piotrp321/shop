<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A product index always has one primary shard (spec: docs/features/search/spec.md, M3 and "Infrastruktura"): the exact
 * facets of the search model and the ranking need it. The variable SEARCH_SHARDS no longer exists, so a container that
 * still has it with a value other than 1 (compose, .env, the chart or a hand-set variable) refuses to build the index
 * definition, with the name of the variable.
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

    public function testTheVariableSetToOneIsAccepted(): void
    {
        $_SERVER[self::VARIABLE] = '1';

        self::assertSame(1, $this->shardsOfTheNewIndex());
    }

    #[DataProvider('valuesOtherThanOne')]
    public function testAnyOtherValueOfTheVariableIsRefusedWithItsName(string $value): void
    {
        $_SERVER[self::VARIABLE] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::VARIABLE);

        $this->shardsOfTheNewIndex();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesOtherThanOne(): iterable
    {
        yield 'two' => ['2'];
        yield 'four' => ['4'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['many'];
        yield 'empty' => [''];
        yield 'one with a leading zero' => ['01'];
        yield 'one with a space' => [' 1'];
        yield 'one with a newline' => ["1\n"];
    }

    private function shardsOfTheNewIndex(): mixed
    {
        $definition = self::getContainer()->get(ProductIndexDefinition::class);
        self::assertInstanceOf(ProductIndexDefinition::class, $definition);

        return $definition->body()['settings']['number_of_shards'];
    }
}
