<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\ListSitemapProductChunks;
use App\Catalog\Application\Query\ListSitemapProductChunksHandler;
use App\Catalog\Application\Query\SitemapChunk;
use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalSitemapProductSource;
use App\Tests\Support\HookedConnection;
use App\Tests\Unit\Ordering\SpyLogger;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The guarantees of the cut of the sitemap chunks (docs/features/admin-queries/spec.md, G1 to G3), on rows that are
 * committed (the test connections are outside the DAMA transaction of the kernel; the rows are deleted after the
 * test), because the cut is one read-only REPEATABLE READ transaction of its own and other connections change the data
 * while it runs: a test closure runs on `HookedConnection` right after a chosen statement of the cut.
 */
final class DbalSitemapProductSourceCutTest extends KernelTestCase
{
    private Connection $control;
    private HookedConnection $cutting;
    private string $category;
    /** @var list<int> numbers of the hooks that ran, in order */
    private array $hooksThatRan = [];

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $cutting = DriverManager::getConnection(['wrapperClass' => HookedConnection::class] + $params, new DbalConfiguration());
        self::assertInstanceOf(HookedConnection::class, $cutting);
        $this->cutting = $cutting;

        self::assertSame(0, $this->control->fetchOne('SELECT count(*) FROM catalog_product'), 'The test needs a catalogue without committed products.');
        $this->category = Uuid::v7()->toRfc4122();
        $this->control->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [$this->category, 'Mapa strony', 'mapa-strony-'.substr($this->category, -12), '/'.$this->category.'/']);
    }

    protected function tearDown(): void
    {
        $this->control->executeStatement('DELETE FROM catalog_product WHERE primary_category_id = ?', [$this->category]);
        $this->control->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$this->category]);
        $this->control->close();
        $this->cutting->close();
        parent::tearDown();
    }

    public function testProductsInsertedAndDeletedWhileTheCutRunsDoNotChangeTheBoundariesOfTheStateItStartedFrom(): void
    {
        $this->insert(10, 20, 30, 40, 50, 60, 70, 80, 90, 100);
        $this->changeTheDataDuringTheCut();

        $boundaries = $this->source(10)->boundaries(3);

        // Positions 4, 7 and 10 of the ten products that existed when the cut started; the boundary 70 was deleted
        // while the cut ran and is still a boundary (the chunks are ranges of values, not lists of rows).
        self::assertSame([self::id(40), self::id(70), self::id(100)], $boundaries);
        self::assertSame([1, 2, 3], $this->hooksThatRan, 'The cut has to run as several statements, the data changed between them.');
    }

    public function testEveryProductOfTheStateTheCutStartedFromIsInExactlyOneChunkAndNoChunkHasMoreThanTheRowLimit(): void
    {
        $this->insert(10, 20, 30, 40, 50, 60, 70, 80, 90, 100);
        $snapshot = $this->committedIds();
        $this->changeTheDataDuringTheCut();

        $keys = $this->chunkKeys($this->source(4), 3);

        self::assertSame([1, 2, 3], $this->hooksThatRan);
        $this->assertEveryIdInOneChunkOfAtMost($snapshot, $keys, 4);
    }

    public function testBoundariesAreExactWhenTheStatisticsOfTheTableNoLongerDescribeIt(): void
    {
        // The statistics are taken while the table has ten products, then forty come that they know nothing about.
        $this->insert(1, 2, 3, 4, 5, 6, 7, 8, 9, 10);
        $this->control->executeStatement('ANALYZE catalog_product');
        $this->control->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, primary_category_id, version, status, updated_at)
             SELECT ('00000000-0000-4000-8000-' || lpad((1000 + n)::text, 12, '0'))::uuid, 'masowy-'||n, 'Produkt', '', 'standard', '[]'::jsonb, '[]'::jsonb, CAST(? AS uuid), 1, 'published', now()
             FROM generate_series(1, 40) AS n",
            [$this->category],
        );
        $snapshot = $this->committedIds();
        self::assertCount(50, $snapshot);

        $keys = $this->chunkKeys($this->source(5), 5);

        self::assertCount(10, $keys);
        $this->assertEveryIdInOneChunkOfAtMost($snapshot, $keys, 5);
    }

    public function testAChunkSizeAboveTheRowLimitIsCutByTheRowLimit(): void
    {
        $this->insert(1, 2, 3, 4, 5, 6, 7, 8, 9, 10);

        $boundaries = $this->source(4)->boundaries(6);

        self::assertSame([self::id(5), self::id(9)], $boundaries);
    }

    public function testThereAreNoBoundariesWithoutProductsOrWithAtMostAChunkOfThem(): void
    {
        self::assertSame([], $this->source(10)->boundaries(3));

        $this->insert(1, 2, 3);

        self::assertSame([], $this->source(10)->boundaries(3));
    }

    public function testTheCutIsOneReadOnlyRepeatableReadTransactionThatEndsWithTheCut(): void
    {
        $this->insert(1, 2, 3, 4, 5, 6, 7);
        $inside = [];
        $this->cutting->afterFetch[1] = function () use (&$inside): void {
            $inside = $this->cutting->fetchAssociative("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
        };

        $this->source(10)->boundaries(3);

        self::assertSame(['isolation' => 'repeatable read', 'read_only' => 'on'], $inside);
        self::assertFalse($this->cutting->isTransactionActive());
        self::assertSame('read committed', $this->cutting->fetchOne('SHOW transaction_isolation'));
    }

    public function testAFailureInTheMiddleOfTheCutRollsTheTransactionBack(): void
    {
        $this->insert(1, 2, 3, 4, 5, 6, 7);
        $this->cutting->afterFetch[2] = static function (): void {
            throw new \RuntimeException('The connection broke.');
        };

        try {
            $this->source(10)->boundaries(2);
            self::fail('The failure has to reach the caller.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The connection broke.', $failure->getMessage());
        }

        self::assertFalse($this->cutting->isTransactionActive());
        self::assertSame(7, $this->cutting->fetchOne('SELECT count(*) FROM catalog_product'));
        self::assertSame('read committed', $this->cutting->fetchOne('SHOW transaction_isolation'));
    }

    public function testACutInsideATransactionOfTheCallerJoinsItAndLeavesItOpen(): void
    {
        $this->insert(1, 2, 3, 4, 5, 6, 7);
        $this->cutting->beginTransaction();

        try {
            $boundaries = $this->source(10)->boundaries(3);

            self::assertSame([self::id(4), self::id(7)], $boundaries);
            self::assertTrue($this->cutting->isTransactionActive());
        } finally {
            $this->cutting->rollBack();
        }
    }

    /**
     * After each of the first three statements of the cut other connections commit changes: products inserted below,
     * inside and above the part that is already read, and deleted ones ahead of the cut and (hook 2) the boundary
     * that has just been read.
     */
    private function changeTheDataDuringTheCut(): void
    {
        $this->hooksThatRan = [];
        $this->cutting->afterFetch[1] = function (): void {
            $this->hooksThatRan[] = 1;
            $this->insert(5, 41, 42, 71);
            $this->delete(20, 50);
        };
        $this->cutting->afterFetch[2] = function (): void {
            $this->hooksThatRan[] = 2;
            $this->delete(70);
            $this->insert(11, 12);
        };
        $this->cutting->afterFetch[3] = function (): void {
            $this->hooksThatRan[] = 3;
            $this->delete(90);
            $this->insert(101);
        };
    }

    /**
     * @return list<SitemapChunkKey>
     */
    private function chunkKeys(DbalSitemapProductSource $source, int $chunkSize): array
    {
        $list = new ListSitemapProductChunksHandler($source, $chunkSize)(new ListSitemapProductChunks());

        return array_map(static fn (SitemapChunk $chunk): SitemapChunkKey => SitemapChunkKey::decode($chunk->key), $list->chunks);
    }

    /**
     * @param list<string>          $ids  the ids of the state the cut started from
     * @param list<SitemapChunkKey> $keys
     */
    private function assertEveryIdInOneChunkOfAtMost(array $ids, array $keys, int $limit): void
    {
        self::assertNull($keys[0]->from, 'The first chunk has no start.');
        self::assertNull($keys[array_key_last($keys)]->to, 'The last chunk has no end.');
        $perChunk = array_fill(0, \count($keys), 0);
        foreach ($ids as $id) {
            $containing = [];
            foreach ($keys as $index => $key) {
                if ((null === $key->from || $id >= $key->from) && (null === $key->to || $id < $key->to)) {
                    $containing[] = $index;
                }
            }
            self::assertCount(1, $containing, 'Product '.$id.' is in chunks '.implode(', ', $containing));
            ++$perChunk[$containing[0]];
        }
        foreach ($perChunk as $index => $count) {
            self::assertLessThanOrEqual($limit, $count, 'Chunk '.$index.' has '.$count.' products.');
        }
    }

    private function source(int $rowLimit): DbalSitemapProductSource
    {
        return new DbalSitemapProductSource($this->cutting, $rowLimit, new SpyLogger());
    }

    private function insert(int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $this->control->executeStatement(
                "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, primary_category_id, version, status, updated_at) VALUES (?, ?, 'Produkt', '', 'standard', '[]', '[]', ?, 1, 'published', now())",
                [self::id($number), 'produkt-'.$number, $this->category],
            );
        }
    }

    private function delete(int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $this->control->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::id($number)]);
        }
    }

    /**
     * @return list<string>
     */
    private function committedIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->control->fetchFirstColumn('SELECT id FROM catalog_product ORDER BY id');

        return $ids;
    }

    private static function id(int $number): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
