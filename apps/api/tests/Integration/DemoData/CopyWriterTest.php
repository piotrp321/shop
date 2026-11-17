<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Copy\CopyWriter;
use App\DemoData\Copy\Sql;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * COPY through the native connection: what goes in is what comes out, for the characters the text format treats
 * specially, for NULL, JSON, times and several batches.
 */
final class CopyWriterTest extends KernelTestCase
{
    private Connection $connection;
    private CopyWriter $writer;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('CREATE TEMPORARY TABLE demo_copy_probe (id int PRIMARY KEY, text_value text, json_value jsonb, at timestamptz, flag boolean)');
        $this->writer = new CopyWriter($connection);
    }

    public function testValuesComeBackAsTheyWentIn(): void
    {
        $values = ['plain', "tab\there", "new\nline", "carriage\rreturn", 'back\\slash', '\\N', 'N', 'zażółć gęślą jaźń', 'a\\tb', '', ' padded '];
        $rows = [];
        foreach ($values as $id => $value) {
            $rows[] = [$id, $value, json_encode(['v' => $value], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('Europe/Warsaw')), 0 === $id % 2];
        }

        $written = $this->writer->copy('demo_copy_probe', ['id', 'text_value', 'json_value', 'at', 'flag'], $rows);

        self::assertSame(\count($values), $written);
        $stored = $this->connection->fetchAllKeyValue('SELECT id, text_value FROM demo_copy_probe ORDER BY id');
        self::assertSame($values, array_values($stored));
        foreach ($this->connection->fetchAllAssociative('SELECT id, json_value, at, flag FROM demo_copy_probe ORDER BY id') as $row) {
            $id = Sql::int($row['id']);
            self::assertSame(['v' => $values[$id]], json_decode(Sql::string($row['json_value']), true, flags: \JSON_THROW_ON_ERROR));
            self::assertSame('2026-03-04 04:06:07+00', Sql::string($this->connection->fetchOne("SELECT to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM demo_copy_probe WHERE id = ?", [$id])));
            self::assertSame(0 === $id % 2, (bool) $row['flag']);
        }
    }

    public function testNullIsNullAndNotTheLetterN(): void
    {
        $this->writer->copy('demo_copy_probe', ['id', 'text_value', 'json_value', 'at', 'flag'], [[1, null, null, null, null], [2, 'N', null, null, null]]);

        self::assertSame([1], array_map(Sql::int(...), $this->connection->fetchFirstColumn('SELECT id FROM demo_copy_probe WHERE text_value IS NULL')));
        self::assertSame([2], array_map(Sql::int(...), $this->connection->fetchFirstColumn("SELECT id FROM demo_copy_probe WHERE text_value = 'N'")));
    }

    public function testManyBatchesAreAllWritten(): void
    {
        $rows = (static function (): \Generator {
            for ($i = 0; $i < 25; ++$i) {
                yield [$i, 'row '.$i, null, null, null];
            }
        })();

        $written = $this->writer->copy('demo_copy_probe', ['id', 'text_value', 'json_value', 'at', 'flag'], $rows, 4);

        self::assertSame(25, $written);
        self::assertSame(25, Sql::int($this->connection->fetchOne('SELECT count(*) FROM demo_copy_probe')));
    }

    public function testARowThatBreaksAConstraintFailsTheCopyWithTheDatabaseError(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/duplicate key/');

        $this->writer->copy('demo_copy_probe', ['id'], [[1], [1]]);
    }

    public function testTheLoadingSessionDoesNotWaitForTheWal(): void
    {
        $this->writer->relaxDurability();

        self::assertSame('off', $this->connection->fetchOne('SHOW synchronous_commit'));
    }
}
