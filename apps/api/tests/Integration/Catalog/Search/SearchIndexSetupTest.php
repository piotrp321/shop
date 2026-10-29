<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\SearchIndexError;
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
        self::assertSame([], $this->maintenance()->setup());

        $targets = $this->aliasTargets();
        self::assertCount(1, $targets);
        self::assertMatchesRegularExpression('/^'.preg_quote($this->alias(), '/').'_\d{17}_[0-9a-f]{6}$/', $targets[0]);
        self::assertSame(new ProductIndexDefinition(1, 0)->hash(), $this->definitionHash($targets[0]));

        self::assertSame([], $this->maintenance()->setup());

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

    public function testIndexFromAnotherDefinitionFailsWithTheReindexInstruction(): void
    {
        $this->maintenance()->setup();
        [$index] = $this->aliasTargets();
        self::esClient()->indices()->putMapping(['index' => $index, 'body' => ['_meta' => ['definition_hash' => 'another']]]);

        $this->assertSetupFails('catalog:reindex');

        self::assertSame([$index], $this->aliasTargets());
        self::assertSame('another', $this->definitionHash($index));
    }

    public function testDefinitionHashIgnoresShardsAndReplicas(): void
    {
        self::assertSame(new ProductIndexDefinition(1, 0)->hash(), new ProductIndexDefinition(3, 2)->hash());
    }

    public function testOrphanedIndexIsReportedNotDeleted(): void
    {
        $this->maintenance()->setup();
        $orphan = $this->maintenance()->createIndex();

        self::assertSame([$orphan], $this->maintenance()->setup());

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
