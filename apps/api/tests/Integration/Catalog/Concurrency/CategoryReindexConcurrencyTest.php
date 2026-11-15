<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Concurrency;

use App\Catalog\Application\Command\ReindexCategoryBatch;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Kernel;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Criterion 2 of ADMIN-FEATURES-11: two deliveries of the same batch at the same moment, each a process of its own on
 * its own connection (ConcurrentCommands, the `test_messaging` kernel, so the next batch is a row of the Doctrine
 * transport `jobs`), handled as a worker does (ReceivedStamp). The lock of the run makes the second wait for the first
 * and see the number it committed: the batch is done once and one message of the next batch is sent. A delivery after
 * the run finished changes nothing. The subtree is 3 products in `test_products` (batches of 2 in the tests); every
 * committed row is deleted after the test and the index recreated.
 */
final class CategoryReindexConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    private const string MESSAGING = 'test_messaging';

    private Connection $control;
    private string $category;
    /** @var list<string> sorted */
    private array $products = [];
    /** @var list<string> */
    private array $runs = [];

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        // The cache of the messaging kernel is warm before the participants boot it at once.
        $messaging = new Kernel(self::MESSAGING, false);
        $messaging->boot();
        $messaging->shutdown();

        $this->maintenance()->recreate();
        $this->category = Uuid::v7()->toRfc4122();
        $this->control->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [$this->category, 'Współbieżność', 'wspolbieznosc-'.substr($this->category, -12), '/'.$this->category.'/']);
        for ($i = 0; $i < 3; ++$i) {
            $productId = Uuid::v7()->toRfc4122();
            $this->control->executeStatement(
                "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, primary_category_id, version, status, updated_at) VALUES (?, ?, 'Produkt', '', 'standard', '[]', '[]', ?, 1, 'published', now())",
                [$productId, 'produkt-'.$productId, $this->category],
            );
            $this->products[] = $productId;
        }
        sort($this->products);
        $indexing = self::getContainer()->get(ProductIndexing::class);
        \assert($indexing instanceof ProductIndexing);
        foreach ($this->products as $productId) {
            $indexing->reindex($productId);
        }
        $factory = self::getContainer()->get('test.'.ElasticsearchClientFactory::class);
        \assert($factory instanceof ElasticsearchClientFactory);
        $alias = self::getContainer()->getParameter('app.search.alias');
        self::assertSame('test_products', $alias);
        $factory->create(30.0)->indices()->refresh(['index' => $alias]);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->runs) {
            foreach ($this->runs as $runId) {
                $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'jobs' AND position(? IN body) > 0", [$runId]);
            }
            $this->control->executeStatement('DELETE FROM catalog_category_reindex WHERE id IN (?)', [$this->runs], [ArrayParameterType::STRING]);
        }
        if ([] !== $this->products) {
            $this->control->executeStatement('DELETE FROM catalog_product WHERE id IN (?)', [$this->products], [ArrayParameterType::STRING]);
        }
        $this->control->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$this->category]);
        $this->control->close();
        $this->maintenance()->recreate();
        parent::tearDown();
    }

    public function testTwoDeliveriesOfTheSameBatchDoItOnceAndSendOneNextBatch(): void
    {
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $runId = $this->reindexRun(null, 0, false);

            $outcomes = ConcurrentCommands::run($this->control, [self::delivery($runId, 0), self::delivery($runId, 0)], self::MESSAGING);

            $message = 'Round '.$round.': '.json_encode($outcomes, \JSON_THROW_ON_ERROR);
            self::assertSame([Outcome::SUCCESS, Outcome::SUCCESS], array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes), $message);
            self::assertSame(['cursor' => $this->products[1], 'next_batch' => 1, 'finished_at' => null], $this->runRow($runId), $message.': one batch of 2 done');
            self::assertSame(1, $this->batchMessages($runId), $message.': one message of the next batch');
        }
    }

    public function testADeliveryAfterTheRunFinishedChangesNothing(): void
    {
        $runId = $this->reindexRun($this->products[2], 2, true);
        $before = $this->runRow($runId);

        $outcomes = ConcurrentCommands::run($this->control, [self::delivery($runId, 2), self::delivery($runId, 1)], self::MESSAGING);

        self::assertSame([Outcome::SUCCESS, Outcome::SUCCESS], array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes));
        self::assertSame($before, $this->runRow($runId));
        self::assertSame(0, $this->batchMessages($runId));
    }

    private function reindexRun(?string $cursor, int $nextBatch, bool $finished): string
    {
        $runId = Uuid::v7()->toRfc4122();
        $this->control->executeStatement(
            'INSERT INTO catalog_category_reindex (id, category_id, cursor, next_batch, finished_at) VALUES (?, ?, ?, ?, '.($finished ? 'now()' : 'NULL').')',
            [$runId, $this->category, $cursor, $nextBatch],
        );
        $this->runs[] = $runId;

        return $runId;
    }

    private static function delivery(string $runId, int $batch): Envelope
    {
        return new Envelope(new ReindexCategoryBatch($runId, $batch), [new ReceivedStamp('jobs')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runRow(string $runId): array
    {
        $row = $this->control->fetchAssociative('SELECT cursor, next_batch, finished_at FROM catalog_category_reindex WHERE id = ?', [$runId]);
        self::assertIsArray($row);

        return $row;
    }

    private function batchMessages(string $runId): int
    {
        $count = $this->control->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'jobs' AND position(? IN body) > 0", [$runId]);
        self::assertIsInt($count);

        return $count;
    }

    private function maintenance(): SearchIndexMaintenance
    {
        $maintenance = self::getContainer()->get(SearchIndexMaintenance::class);
        \assert($maintenance instanceof SearchIndexMaintenance);

        return $maintenance;
    }
}
