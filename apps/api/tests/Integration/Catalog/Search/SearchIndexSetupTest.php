<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SetupResult;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresSearchIndexRebuildLock;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use App\Catalog\UI\Console\SetupSearchIndexCommand;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchIndexSetupTest extends KernelTestCase
{
    use UsesTemporarySearchAlias;

    public function testSetupCreatesTheAliasAndOneIndexThenChangesNothing(): void
    {
        self::assertEquals(new SetupResult(), $this->maintenance()->setup());

        $targets = $this->aliasTargets();
        self::assertCount(1, $targets);
        self::assertMatchesRegularExpression('/^'.preg_quote($this->alias(), '/').'_\d{17}_[0-9a-f]{6}$/', $targets[0]);
        self::assertSame(new ProductIndexDefinition(0)->hash(), $this->definitionHash($targets[0]));

        self::assertEquals(new SetupResult(), $this->maintenance()->setup());

        self::assertSame($targets, $this->aliasTargets());
        self::assertSame($targets, $this->aliasIndexes());
    }

    public function testPlainIndexNamedLikeTheAliasFailsWithoutChanges(): void
    {
        self::esClient()->indices()->create(['index' => $this->alias()]);

        $this->assertSetupFails('plain index');

        self::assertTrue($this->indexExists($this->alias()));
        self::assertSame([], $this->aliasIndexes());
    }

    public function testAliasOnTwoIndexesFailsWithoutChanges(): void
    {
        $this->maintenance()->setup();
        $second = $this->maintenance()->createIndex();
        self::esClient()->indices()->putAlias(['index' => $second, 'name' => $this->alias()]);
        $before = $this->aliasTargets();
        self::assertCount(2, $before);

        $this->assertSetupFails('2 indexes');

        self::assertSame($before, $this->aliasTargets());
    }

    public function testIndexFromAnotherDefinitionIsLeftAloneWithoutFailing(): void
    {
        $this->maintenance()->setup();
        [$index] = $this->aliasTargets();
        $this->markAsBuiltFromAnotherDefinition($index);

        $result = $this->maintenance()->setup();

        self::assertSame($index, $result->outdatedIndex);
        self::assertSame([], $result->orphans);
        self::assertSame([$index], $this->aliasTargets());
        self::assertSame([$index], $this->aliasIndexes());
        self::assertSame('another', $this->definitionHash($index));
    }

    public function testIndexWithMoreThanOneShardIsReportedAsOutdatedAndLeftAlone(): void
    {
        $index = $this->createIndexWithShards(4);

        $result = $this->maintenance()->setup();

        self::assertSame($index, $result->outdatedIndex);
        self::assertSame([$index], $this->aliasTargets());
        self::assertSame([$index], $this->aliasIndexes());
    }

    public function testCommandWarnsAboutAnIndexWithMoreThanOneShardWithTheReindexInstructionAndSucceeds(): void
    {
        $index = $this->createIndexWithShards(4);
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString($index, $display);
        self::assertStringContainsString('catalog:reindex', $display);
        self::assertStringContainsString('WARNING', $display);
        self::assertSame([$index], $this->aliasTargets());
    }

    public function testIndexOfAnotherDefinitionWithMoreThanOneShardIsReportedOnceAndLeftAlone(): void
    {
        $index = $this->createIndexWithShards(4);
        $this->markAsBuiltFromAnotherDefinition($index);

        $result = $this->maintenance()->setup();

        self::assertSame($index, $result->outdatedIndex);
        self::assertSame([$index], $this->aliasTargets());
    }

    public function testDefinitionHashIgnoresShardsReplicasAndTheRefreshInterval(): void
    {
        self::assertSame(new ProductIndexDefinition(0)->hash(), new ProductIndexDefinition(2, '5s')->hash());
    }

    public function testSetupCreatesTheIndexWithTheRefreshIntervalOfTheDefinition(): void
    {
        $this->maintenance(definition: new ProductIndexDefinition(0, '5s'))->setup();

        self::assertSame('5s', $this->refreshInterval($this->aliasTargets()[0]));
    }

    public function testSetupSetsTheRefreshIntervalOfTheDefinitionOnAnExistingIndexWithoutRebuildingIt(): void
    {
        $this->maintenance()->setup();
        [$index] = $this->aliasTargets();
        self::assertSame('1s', $this->refreshInterval($index));
        self::esClient()->index(['index' => $this->alias(), 'id' => 'x', 'body' => $this->document('x'), 'refresh' => 'true']);

        $result = $this->maintenance(definition: new ProductIndexDefinition(0, '5s'))->setup();

        self::assertEquals(new SetupResult(), $result);
        self::assertSame([$index], $this->aliasTargets());
        self::assertSame([$index], $this->aliasIndexes());
        self::assertSame('5s', $this->refreshInterval($index));
        self::assertSame(1, $this->documentCount($index));

        $this->maintenance(definition: new ProductIndexDefinition(0, '500ms'))->setup();

        self::assertSame('500ms', $this->refreshInterval($index));
    }

    public function testSetupSetsTheRefreshIntervalOnAnIndexWithoutAnExplicitOneToo(): void
    {
        $this->maintenance()->setup();
        [$index] = $this->aliasTargets();
        self::esClient()->indices()->putSettings(['index' => $index, 'body' => ['index' => ['refresh_interval' => null]]]);
        self::assertNull($this->refreshInterval($index));

        $this->maintenance()->setup();

        self::assertSame('1s', $this->refreshInterval($index));
    }

    public function testSetupSetsTheRefreshIntervalOnAnIndexFromAnotherDefinitionToo(): void
    {
        $this->maintenance()->setup();
        [$index] = $this->aliasTargets();
        $this->markAsBuiltFromAnotherDefinition($index);

        $result = $this->maintenance(definition: new ProductIndexDefinition(0, '5s'))->setup();

        self::assertSame($index, $result->outdatedIndex);
        self::assertSame('5s', $this->refreshInterval($index));
    }

    public function testSetupLeavesTheIndexOfARebuildInProgressWithItsDisabledRefresh(): void
    {
        $this->maintenance()->setup();
        $rebuilt = $this->maintenance()->createIndex();

        $this->maintenance(definition: new ProductIndexDefinition(0, '5s'))->setup();

        self::assertSame('-1', $this->refreshInterval($rebuilt));
    }

    public function testOrphanedIndexIsReportedNotDeleted(): void
    {
        $this->maintenance()->setup();
        $orphan = $this->maintenance()->createIndex();

        self::assertSame([$orphan], $this->maintenance()->setup()->orphans);

        self::assertTrue($this->indexExists($orphan));
        self::assertNotContains($orphan, $this->aliasTargets());
    }

    public function testRecreateDeletesTheAliasAndAllItsIndexesThenCreatesThemAgain(): void
    {
        $this->maintenance()->setup();
        [$previous] = $this->aliasTargets();
        $orphan = $this->maintenance()->createIndex();
        self::esClient()->index(['index' => $this->alias(), 'id' => 'x', 'body' => $this->document('x'), 'refresh' => 'true']);

        $this->maintenance()->recreate();

        $targets = $this->aliasTargets();
        self::assertCount(1, $targets);
        self::assertNotSame($previous, $targets[0]);
        self::assertSame($targets, $this->aliasIndexes(), 'The previous index and the orphan are gone.');
        self::assertFalse($this->indexExists($orphan));
        self::assertSame(0, $this->documentCount($targets[0]));
    }

    public function testCommandCreatesTheIndexAndWarnsAboutOrphans(): void
    {
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $orphan = $this->maintenance()->createIndex();

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString($orphan, $tester->getDisplay());
    }

    public function testCommandWarnsWithTheReindexInstructionAboutAnIndexFromAnotherDefinitionAndSucceeds(): void
    {
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        [$index] = $this->aliasTargets();
        $this->markAsBuiltFromAnotherDefinition($index);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString($index, $display);
        self::assertStringContainsString('catalog:reindex', $display);
        self::assertStringContainsString('WARNING', $display);
        self::assertSame([$index], $this->aliasTargets());
        self::assertSame('another', $this->definitionHash($index));
    }

    public function testCommandReportsOrphansAndAnIndexFromAnotherDefinitionTogether(): void
    {
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        [$index] = $this->aliasTargets();
        $this->markAsBuiltFromAnotherDefinition($index);
        $orphan = $this->maintenance()->createIndex();

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString($orphan, $display);
        self::assertStringContainsString('catalog:reindex', $display);
    }

    public function testCommandStaysSilentAboutTheDefinitionWhenItIsCurrent(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringNotContainsString('catalog:reindex', $tester->getDisplay());
    }

    public function testCommandStillFailsOnAPlainIndexAndOnTwoIndexesBehindTheAlias(): void
    {
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $second = $this->maintenance()->createIndex();
        self::esClient()->indices()->putAlias(['index' => $second, 'name' => $this->alias()]);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('2 indexes', $tester->getDisplay());
    }

    public function testCommandRefusesToWarnWhileTheRebuildLockIsHeldEvenForAnIndexFromAnotherDefinition(): void
    {
        $this->maintenance()->setup();
        [$index] = $this->aliasTargets();
        $this->markAsBuiltFromAnotherDefinition($index);
        $holder = $this->independentConnection();
        self::assertTrue($this->lock($holder)->tryAcquire());

        try {
            $tester = $this->commandTester();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('rebuild or setup', $tester->getDisplay());
        } finally {
            $holder->close();
        }
    }

    public function testCommandFailsWithCodeOneAndTheInstruction(): void
    {
        self::esClient()->indices()->create(['index' => $this->alias()]);
        $tester = $this->commandTester();

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('plain index', $tester->getDisplay());
    }

    public function testRecreateIsRefusedInProd(): void
    {
        $this->maintenance()->setup();
        $before = $this->aliasTargets();
        $tester = $this->commandTester('prod');

        self::assertSame(Command::FAILURE, $tester->execute(['--recreate' => true]));

        self::assertSame($before, $this->aliasTargets());
    }

    public function testCommandRecreates(): void
    {
        $this->maintenance()->setup();
        $before = $this->aliasTargets();

        self::assertSame(Command::SUCCESS, $this->commandTester()->execute(['--recreate' => true]));

        self::assertCount(1, $this->aliasTargets());
        self::assertNotSame($before, $this->aliasTargets());
    }

    public function testCommandRefusesWhileAnotherProcessHoldsTheRebuildLock(): void
    {
        $holder = $this->independentConnection();
        self::assertTrue($this->lock($holder)->tryAcquire());

        try {
            $tester = $this->commandTester();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertSame([], $this->aliasIndexes());
        } finally {
            $holder->close();
        }
    }

    private function assertSetupFails(string $messagePart): void
    {
        try {
            $this->maintenance()->setup();
            self::fail('setup() did not fail.');
        } catch (SearchIndexError $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    /**
     * An index of the current definition, but with more primary shards than the definition allows, behind the alias:
     * what an environment that still built indexes with several shards left behind.
     */
    private function createIndexWithShards(int $shards): string
    {
        $body = new ProductIndexDefinition(0)->body();
        $body['settings']['number_of_shards'] = $shards;
        $index = $this->alias().'_20260101000000000_'.bin2hex(random_bytes(3));
        self::esClient()->indices()->create(['index' => $index, 'body' => $body + ['aliases' => [$this->alias() => new \stdClass()]]]);

        return $index;
    }

    private function markAsBuiltFromAnotherDefinition(string $index): void
    {
        self::esClient()->indices()->putMapping(['index' => $index, 'body' => ['_meta' => ['definition_hash' => 'another']]]);
    }

    private function commandTester(string $environment = 'test'): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SetupSearchIndexCommand($this->maintenance(), $this->lock($this->connection()), $environment));

        return new CommandTester($application->find('catalog:search-index:setup'));
    }

    private function lock(Connection $connection): PostgresSearchIndexRebuildLock
    {
        // The temporary alias keeps the lock apart from other tests and from test_products.
        return new PostgresSearchIndexRebuildLock($connection, $this->alias());
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function independentConnection(): Connection
    {
        // A plain DBAL configuration, so the DAMA static connection driver is not applied.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection()->getParams();

        return DriverManager::getConnection($params, new DbalConfiguration());
    }

    private function definitionHash(string $index): mixed
    {
        $response = self::esClient()->indices()->getMapping(['index' => $index]);
        \assert($response instanceof Elasticsearch);

        $mapping = $response->asArray()[$index] ?? null;
        \assert(\is_array($mapping) && \is_array($mapping['mappings']) && \is_array($mapping['mappings']['_meta']));

        return $mapping['mappings']['_meta']['definition_hash'] ?? null;
    }

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

    private function documentCount(string $index): int
    {
        self::esClient()->indices()->refresh(['index' => $index]);
        $response = self::esClient()->count(['index' => $index]);
        \assert($response instanceof Elasticsearch);
        $count = $response->asArray()['count'];
        \assert(\is_int($count));

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $id): array
    {
        return [
            'id' => $id, 'version' => 1, 'name' => 'Name', 'slug' => 'slug-'.$id, 'short_description' => '',
            'main_category_id' => 'c', 'category_ids' => ['c'], 'category_ancestor_ids' => ['c'], 'main_image' => null,
        ];
    }
}
