<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalBackofficeOrderReader;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The plan of the search of the order list by fragment on 100 000 orders, as the planner chooses it on its own
 * statistics (after ANALYZE), not steered by switches: the combined trigram index is read through a bitmap, and the
 * table is not read whole.
 *
 * The orders are in a temporary table that takes the place of ordering_order for the connection of the test (the
 * pg_temp schema comes first in the search path): a copy of its definition with its indexes, so the table of the test
 * database is not filled with rows that the rollback leaves behind as garbage, and the test costs a few seconds.
 */
final class OrderSearchPlanTest extends KernelTestCase
{
    private const int ORDERS = 100_000;
    private const string UNIQUE_SURNAME = 'Wiśniewski-Kowalczyk';
    private const string UNIQUE_EMAIL = 'hieronim.zielonka@example.org';

    private Connection $connection;
    private RecordingConnection $recording;
    private DbalBackofficeOrderReader $reader;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE ordering_order (LIKE public.ordering_order INCLUDING ALL)');
        $this->fill();
        $this->connection->executeStatement('ANALYZE ordering_order');
        $this->recording = RecordingConnection::like($this->connection);
        $this->reader = new DbalBackofficeOrderReader($this->recording, new BoundedCounter($this->recording));
    }

    /**
     * One test for all of them, so the 100 000 orders are written and analysed once.
     */
    public function testAFragmentOfTheNumberTheEmailOrTheNameIsReadThroughTheCombinedIndexNotByAScanOfTheTable(): void
    {
        $expected = "USING gin (lower((((((number)::text || '\x1f'::text) || (email)::text) || '\x1f'::text) || (full_name)::text)) gin_trgm_ops)";
        $index = $this->trigramIndex();
        self::assertSame(self::ORDERS, $this->connection->fetchOne('SELECT count(*) FROM ordering_order'));
        self::assertStringEndsWith($expected, $this->indexDefinition("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'ordering_order_search_trgm_idx'"));
        self::assertStringEndsWith($expected, $this->indexDefinition("SELECT indexdef FROM pg_indexes WHERE indexname = '{$index}'"));
        $day = static fn (string $at): \DateTimeImmutable => new \DateTimeImmutable($at);

        $filters = [
            'a part of a number' => new BackofficeOrderFilter(null, false, null, null, null, 'R-150001'),
            'a part of an email' => new BackofficeOrderFilter(null, false, null, null, null, 'hieronim.zielonka@'),
            'a part of an email with an @' => new BackofficeOrderFilter(null, false, null, null, null, 'zielonka@exam'),
            'a part of a surname' => new BackofficeOrderFilter(null, false, null, null, null, 'wiśniewski-kow'),
            'a surname in capitals' => new BackofficeOrderFilter(null, false, null, null, null, 'WIŚNIEWSKI-KOWALCZYK'),
            'with a status and a range of days' => new BackofficeOrderFilter(OrderStatus::Paid, false, null, null, BackofficeOrderSort::PlacedAscending, 'zielonka@exam', $day('2026-01-01 00:00:00+00:00'), $day('2027-01-01 00:00:00+00:00')),
        ];
        foreach ($filters as $label => $filter) {
            self::assertNotSame([], $this->reader->page($filter, null, 26), $label);
            $page = $this->recording->planOfTheLastQuery($this->connection, false);
            $this->reader->count($filter);
            $count = $this->recording->planOfTheLastQuery($this->connection, false);

            foreach ([$page, $count] as $plan) {
                self::assertStringContainsString('Bitmap Index Scan on '.$index, $plan, $label."\n".$plan);
                self::assertDoesNotMatchRegularExpression('/Seq Scan on ordering_order(?![_a-z])/', $plan, $label."\n".$plan);
            }
        }
    }

    private function indexDefinition(string $sql): string
    {
        $definition = $this->connection->fetchOne($sql);
        self::assertIsString($definition);

        return $definition;
    }

    private function trigramIndex(): string
    {
        $name = $this->connection->fetchOne("SELECT indexname FROM pg_indexes WHERE tablename = 'ordering_order' AND schemaname LIKE 'pg_temp%' AND indexdef LIKE '%gin_trgm_ops%'");
        self::assertIsString($name);

        return $name;
    }

    /**
     * 100 000 paid orders of guests with numbers from R-100001, emails and names of many different characters, and
     * one with a surname and an email nobody else has.
     */
    private function fill(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ordering_order (
                    id, number, status, owner_type, customer_id, access_token, email, full_name, phone, street, postal_code, city, country, currency,
                    total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, paid_at, payment_id, version
                )
                SELECT gen_random_uuid(), 'R-'||(100000 + n), 'paid', 'guest', NULL, substr(md5('t'||n)||md5('u'||n), 1, 43),
                       CASE WHEN n = 77777 THEN :email ELSE substr(md5('e'||n), 1, 12)||'@'||(ARRAY['gmail.com', 'wp.pl', 'example.com', 'onet.pl', 'o2.pl'])[1 + n % 5] END,
                       CASE WHEN n = 77777 THEN 'Hieronim '||:surname
                            ELSE (ARRAY['Anna', 'Jan', 'Piotr', 'Maria', 'Krzysztof', 'Zofia', 'Tomasz', 'Ewa'])[1 + n % 8]||' '||substr(md5('n'||n), 1, 3)||(ARRAY['ski', 'wicz', 'czyk', 'ak', 'ek'])[1 + n % 5] END,
                       '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 0, 1000,
                       'plan-'||n, repeat('a', 64), timestamptz '2026-01-01 00:00:00+00' + n * interval '5 minutes',
                       timestamptz '2026-01-01 00:30:00+00' + n * interval '5 minutes', timestamptz '2026-01-01 00:05:00+00' + n * interval '5 minutes', gen_random_uuid(), 1
                FROM generate_series(1, :orders) AS n
                SQL,
            ['email' => self::UNIQUE_EMAIL, 'surname' => self::UNIQUE_SURNAME, 'orders' => self::ORDERS],
        );
    }
}
