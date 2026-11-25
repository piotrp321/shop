<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The number of primary shards of a new product index comes from SEARCH_SHARDS of the environment (spec:
 * docs/features/search/spec.md, "Infrastruktura"); without the variable an index has one shard.
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

        self::assertSame(1, self::getContainer()->getParameter('app.search.shards'));
    }

    public function testTheVariableSetsTheNumberOfShards(): void
    {
        $_SERVER[self::VARIABLE] = '4';

        self::assertSame(4, self::getContainer()->getParameter('app.search.shards'));
    }
}
