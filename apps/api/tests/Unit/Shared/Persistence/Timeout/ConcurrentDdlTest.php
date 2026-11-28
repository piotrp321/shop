<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Persistence\Timeout;

use App\Shared\Infrastructure\Persistence\Timeout\TimeoutConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A statement of the CONCURRENTLY kind waits for the transactions that started before it, whatever they take (a query
 * of the panel, a worker, the generator, a dump), and it does not block anything while it waits: no lock timeout
 * applies to it, or it ends with an INVALID index and every retry of the migration ends the same way (spec:
 * docs/features/runtime-config/spec.md, "Limity czasu w bazie").
 */
final class ConcurrentDdlTest extends TestCase
{
    #[DataProvider('concurrentStatements')]
    public function testAStatementThatRunsConcurrentlyIsRecognized(string $sql): void
    {
        self::assertTrue(TimeoutConnection::isConcurrentDdl($sql), $sql);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function concurrentStatements(): iterable
    {
        yield 'create index' => ['CREATE INDEX CONCURRENTLY IF NOT EXISTS idx ON t (a)'];
        yield 'create unique index' => ['CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS idx ON t (a)'];
        yield 'drop index' => ['DROP INDEX CONCURRENTLY IF EXISTS idx'];
        yield 'reindex' => ['REINDEX INDEX CONCURRENTLY idx'];
        yield 'reindex with options' => ['REINDEX (VERBOSE) TABLE CONCURRENTLY t'];
        yield 'detach partition' => ['ALTER TABLE t DETACH PARTITION p CONCURRENTLY'];
        yield 'lower case and leading white space' => ["  \n create index concurrently idx on t (a)"];
        yield 'the keyword on the next line' => ["CREATE INDEX\n    CONCURRENTLY idx ON t (a)"];
    }

    #[DataProvider('otherStatements')]
    public function testAnyOtherStatementIsNot(string $sql): void
    {
        self::assertFalse(TimeoutConnection::isConcurrentDdl($sql), $sql);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherStatements(): iterable
    {
        yield 'create index' => ['CREATE INDEX idx ON t (a)'];
        yield 'alter table' => ['ALTER TABLE t ADD COLUMN note text'];
        yield 'a column with the word in its name' => ['ALTER TABLE t ADD COLUMN concurrently_note text'];
        yield 'a select with the text' => ["SELECT 'CREATE INDEX CONCURRENTLY idx ON t (a)'"];
        yield 'an insert with the text' => ["INSERT INTO t (note) VALUES ('DROP INDEX CONCURRENTLY idx')"];
        yield 'lock table' => ['LOCK TABLE t IN ACCESS EXCLUSIVE MODE'];
    }

    /**
     * The class: every statement with CONCURRENTLY that a migration adds, today and in the next ones, is recognized by
     * the beginning of its text (what stands before the first variable of the string).
     */
    public function testEveryConcurrentStatementOfTheMigrationsIsRecognized(): void
    {
        $files = glob(\dirname(__DIR__, 5).'/migrations/Version*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $found = 0;
        foreach ($files as $file) {
            $lines = file($file);
            self::assertIsArray($lines);
            foreach ($lines as $number => $line) {
                if (!str_contains($line, 'CONCURRENTLY') || !str_contains($line, 'addSql(')) {
                    continue;
                }
                ++$found;
                self::assertSame(1, preg_match('/addSql\(\s*(["\'])(.*?)(?:\{\$|\1)/', $line, $literal), basename($file).':'.($number + 1).' is not a string literal that this test can read');
                self::assertTrue(TimeoutConnection::isConcurrentDdl($literal[2]), basename($file).':'.($number + 1).': '.$literal[2]);
            }
        }
        self::assertGreaterThanOrEqual(9, $found, 'the migrations with CREATE or DROP INDEX CONCURRENTLY');
    }
}
