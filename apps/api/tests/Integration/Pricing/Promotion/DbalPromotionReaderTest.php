<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Promotion;

use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Pricing\Infrastructure\Persistence\Doctrine\DbalPromotionReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list of promotions of the panel on PostgreSQL, in a transaction rolled back after each test.
 */
final class DbalPromotionReaderTest extends TestCase
{
    private const string NOW = '2026-11-10T12:00:00Z';
    private const string PLANNED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6001';
    private const string ACTIVE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6002';
    private const string ACTIVE_SAME_START = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6003';
    private const string ENDS_NOW = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6004';
    private const string STARTS_NOW = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6005';

    private Connection $connection;
    private DbalPromotionReader $reader;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->connection->beginTransaction();
        // Only the promotions of the test are in the list.
        $this->connection->executeStatement('DELETE FROM pricing_promotion');
        $this->reader = new DbalPromotionReader($this->connection);

        $this->insert(self::PLANNED, '2026-11-20 00:00:00+00', '2026-11-30 00:00:00+00', 1);
        $this->insert(self::ACTIVE, '2026-11-01 00:00:00+00', '2026-11-15 00:00:00+00', 3);
        $this->insert(self::ACTIVE_SAME_START, '2026-11-01 00:00:00+00', '2026-11-12 00:00:00+00', 2);
        $this->insert(self::ENDS_NOW, '2026-10-01 00:00:00+00', '2026-11-10 12:00:00+00', 1);
        $this->insert(self::STARTS_NOW, '2026-11-10 12:00:00+00', '2026-11-11 00:00:00+00', 1);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        $this->connection->close();
        parent::tearDown();
    }

    public function testAllPromotionsNewestStartFirstThenById(): void
    {
        $page = $this->reader->page(null, new \DateTimeImmutable(self::NOW), null, 10);

        self::assertSame([self::PLANNED, self::STARTS_NOW, self::ACTIVE, self::ACTIVE_SAME_START, self::ENDS_NOW], array_column($page, 'id'));
        self::assertEquals(
            new ListedPromotion(self::ACTIVE, 'Promocja '.self::ACTIVE, 15, new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-15T00:00:00Z'), 3, 2),
            $page[2],
        );
    }

    /**
     * @return iterable<string, array{PromotionStatus, list<string>}>
     */
    public static function statuses(): iterable
    {
        yield 'planned' => [PromotionStatus::Planned, [self::PLANNED]];
        yield 'active from the start until before the end' => [PromotionStatus::Active, [self::STARTS_NOW, self::ACTIVE, self::ACTIVE_SAME_START]];
        yield 'ended at the end' => [PromotionStatus::Ended, [self::ENDS_NOW]];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('statuses')]
    public function testTheStatusIsTheOneAtNow(PromotionStatus $status, array $ids): void
    {
        self::assertSame($ids, array_column($this->reader->page($status, new \DateTimeImmutable(self::NOW), null, 10), 'id'));
    }

    public function testTheCursorContinuesAfterItsPromotionAlsoWithinTheSameStart(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $first = $this->reader->page(null, $now, null, 3);
        $second = $this->reader->page(null, $now, PromotionCursor::after($first[2]), 3);

        self::assertSame([self::PLANNED, self::STARTS_NOW, self::ACTIVE], array_column($first, 'id'));
        self::assertSame([self::ACTIVE_SAME_START, self::ENDS_NOW], array_column($second, 'id'));
        self::assertSame([self::ENDS_NOW], array_column($this->reader->page(PromotionStatus::Ended, $now, PromotionCursor::after($first[2]), 3), 'id'));
    }

    private function insert(string $id, string $startsAt, string $endsAt, int $variants): void
    {
        $this->connection->executeStatement(
            "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, ?, 15, ?, ?, '2026-09-01 00:00:00+00', '2026-09-01 00:00:00+00', 2)",
            [$id, 'Promocja '.$id, $startsAt, $endsAt],
        );
        for ($i = 0; $i < $variants; ++$i) {
            $this->connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, gen_random_uuid())', [$id]);
        }
    }
}
