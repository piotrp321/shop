<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the source does around the planner settings of its two statements of candidates (decision D10): the settings are in
 * force for those statements and for nothing else, they are one statement, and they are gone after the read whatever
 * the read did, also in a transaction of the caller, which stays open with what it has done. The tests run the production
 * code on a connection that records what it sends (RecordingConnection).
 *
 * A budget of one row makes the walk of a phrase end without a full page on three products, so the candidates are read.
 */
final class ProductPhraseSteeringTest extends KernelTestCase
{
    private const array SETTINGS = ['enable_seqscan', 'enable_indexscan', 'enable_indexonlyscan'];
    private const string CANDIDATES = 'WITH m AS MATERIALIZED';

    private Connection $connection;
    private RecordingConnection $recording;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $category = '00000000-0000-4000-8000-0000000000a1';
        $this->connection->executeStatement("INSERT INTO catalog_category (id, name, slug, path, parent_id, position, version) VALUES ('{$category}', 'Root', 'root', '/{$category}/', NULL, 0, 1)");
        foreach (['Alfa', 'Beta', 'Gamma'] as $number => $name) {
            $this->connection->executeStatement(
                "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
                 VALUES (?, ?, ?, '', 'standard', '[]'::jsonb, ?, 1, '[]'::jsonb, 'published', '2026-11-01 10:00:00+00')",
                [\sprintf('00000000-0000-4000-8000-%012d', $number + 1), 'p-'.$number, $name, $category],
            );
        }
        $this->recording = RecordingConnection::like($this->connection);
    }

    public function testTheSettingsAreOneStatementBeforeTheCandidatesAndAreGoneAfterThem(): void
    {
        $before = $this->settings();

        $page = $this->source(1)->page('beta', null, null, null, ProductSort::Name, 26);

        self::assertSame(['Beta'], $this->names($page));
        $candidates = $this->candidateQueries();
        self::assertCount(1, $candidates);
        $statements = $this->recording->statementsOfQueries[$candidates[0]];
        self::assertCount(1, $statements, 'the settings are one statement');
        foreach (self::SETTINGS as $setting) {
            self::assertStringContainsString("set_config('{$setting}', 'off', true)", $statements[0][0]);
        }
        foreach ($this->recording->queries as $i => [$sql]) {
            if (!str_starts_with($sql, self::CANDIDATES)) {
                self::assertSame([], $this->recording->statementsOfQueries[$i], 'no setting before another statement: '.$sql);
            }
        }
        self::assertSame(['begin', 'rollback'], $this->recording->transactionEvents);
        self::assertSame($before, $this->settings());
    }

    public function testTheSettingsAreInForceForTheCandidatesOfThePageAndOfTheCountOnly(): void
    {
        $seen = [];
        $this->recording->beforeQuery = function (string $sql) use (&$seen): void {
            $seen[] = [str_starts_with($sql, self::CANDIDATES), $this->connection->fetchOne("SELECT current_setting('enable_seqscan')"), $this->connection->fetchOne("SELECT current_setting('enable_indexscan')"), $this->connection->fetchOne("SELECT current_setting('enable_indexonlyscan')")];
        };
        $source = $this->source(1);

        $source->page('beta', null, null, null, ProductSort::Name, 26);
        $source->count('beta', null, null);

        $candidates = array_filter($seen, static fn (array $query): bool => $query[0]);
        self::assertCount(2, $candidates, 'the page and the count read candidates');
        foreach ($seen as [$isCandidates, $seqscan, $indexscan, $indexonlyscan]) {
            self::assertSame($isCandidates ? ['off', 'off', 'off'] : ['on', 'on', 'on'], [$seqscan, $indexscan, $indexonlyscan]);
        }
    }

    public function testAReadInATransactionOfTheCallerLeavesItOpenWithWhatItHasDone(): void
    {
        $before = $this->settings();
        $this->recording->beginTransaction();
        $this->connection->executeStatement('CREATE TEMP TABLE steering_marker AS SELECT 1 AS x');
        $level = $this->recording->getTransactionNestingLevel();

        $page = $this->source(1)->page('beta', null, null, null, ProductSort::Name, 26);

        self::assertSame(['Beta'], $this->names($page));
        self::assertCount(1, $this->candidateQueries());
        self::assertTrue($this->recording->isTransactionActive());
        self::assertSame($level, $this->recording->getTransactionNestingLevel());
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM steering_marker'));
        self::assertSame($before, $this->settings());
        $this->recording->rollBack();
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function failures(): iterable
    {
        foreach ([false, true] as $inTransaction) {
            foreach (['an exception of the code' => 'exception', 'an error of the database that aborts the transaction' => 'database'] as $label => $kind) {
                yield ($inTransaction ? 'in a transaction of the caller, ' : 'alone, ').$label => [$inTransaction, $kind];
            }
        }
    }

    #[DataProvider('failures')]
    public function testAFailureOfTheReadLeavesNoSettingAndNoOpenTransactionAndReachesTheCaller(bool $inTransaction, string $kind): void
    {
        $before = $this->settings();
        if ($inTransaction) {
            $this->recording->beginTransaction();
            $this->connection->executeStatement('CREATE TEMP TABLE steering_marker AS SELECT 1 AS x');
        }
        $level = $this->recording->getTransactionNestingLevel();
        $this->recording->beforeQuery = function (string $sql) use ($kind): void {
            if (str_starts_with($sql, self::CANDIDATES)) {
                if ('database' === $kind) {
                    // The error is made inside the transaction of the read, which leaves it aborted: the statement that follows fails too.
                    $this->connection->executeQuery('SELECT 1 / 0');
                }
                throw new \RuntimeException('The read failed.');
            }
        };

        $caught = null;
        try {
            $this->source(1)->page('beta', null, null, null, ProductSort::Name, 26);
        } catch (\Throwable $failure) {
            $caught = $failure;
        }

        self::assertNotNull($caught, 'The failure must reach the caller.');
        if ('database' === $kind) {
            self::assertInstanceOf(DriverException::class, $caught);
        } else {
            self::assertInstanceOf(\RuntimeException::class, $caught);
            self::assertSame('The read failed.', $caught->getMessage());
        }

        $this->recording->beforeQuery = null;
        self::assertSame($level, $this->recording->getTransactionNestingLevel(), 'no transaction of the read is left open');
        self::assertSame($before, $this->settings());
        self::assertSame(1, $this->connection->fetchOne('SELECT 1'), 'the connection is usable');
        if ($inTransaction) {
            self::assertTrue($this->recording->isTransactionActive());
            self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM steering_marker'));
            $this->recording->rollBack();
        }
    }

    public function testThePageIsTheSameWhateverTheBudgetIsAndWhetherTheWalkReachedTheEndOfTheOrder(): void
    {
        // Three products: a walk of 4 rows or more reaches the end of the order, a walk of 1 row does not.
        foreach ([1, 3, 4, 100] as $budget) {
            $page = $this->source($budget)->page('beta', null, null, null, ProductSort::Name, 26);

            self::assertSame(['Beta'], $this->names($page), 'budget '.$budget);
        }
    }

    private function source(int $budget): DbalBackofficeProductSource
    {
        return new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording), phraseBudget: $budget);
    }

    /**
     * @return list<int> the numbers of the recorded queries that read candidates
     */
    private function candidateQueries(): array
    {
        $found = [];
        foreach ($this->recording->queries as $i => [$sql]) {
            if (str_starts_with($sql, self::CANDIDATES)) {
                $found[] = $i;
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed> the planner settings of the session, as they are now
     */
    private function settings(): array
    {
        $settings = [];
        foreach (self::SETTINGS as $setting) {
            $settings[$setting] = $this->connection->fetchOne("SELECT current_setting('{$setting}')");
        }

        return $settings;
    }

    /**
     * @param list<BackofficeProduct> $products
     *
     * @return list<string>
     */
    private function names(array $products): array
    {
        return array_map(static fn (BackofficeProduct $product): string => $product->name, $products);
    }
}
