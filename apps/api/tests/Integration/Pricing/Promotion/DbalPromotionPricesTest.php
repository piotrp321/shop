<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Promotion;

use App\Pricing\Application\Port\ActivePromotion;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PriorPrice;
use App\Pricing\Domain\Promotion\PriorPromotion;
use App\Pricing\Infrastructure\Persistence\Doctrine\DbalActivePromotions;
use App\Pricing\Infrastructure\Persistence\Doctrine\DbalOmnibusHistory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The reads of the effective price (active promotions) and of the Omnibus price (history and earlier promotions in
 * the window of each variant) on PostgreSQL, for many variants at once, in a transaction rolled back after each test.
 */
final class DbalPromotionPricesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connect();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        $this->connection->close();
        parent::tearDown();
    }

    public function testThePromotionsActiveAtTheMomentFromTheStartUntilBeforeTheEnd(): void
    {
        [$active, $startsNow, $endsNow, $planned, $none, $notAsked] = $this->variants(6);
        $autumn = $this->promotion('Jesień', 20, '2026-11-01 00:00:00+00', '2026-11-20 00:00:00+00', [$active, $notAsked]);
        $fromNow = $this->promotion('Od teraz', 10, '2026-11-10 12:00:00+00', '2026-11-11 00:00:00+00', [$startsNow]);
        $this->promotion('Koniec', 30, '2026-11-01 00:00:00+00', '2026-11-10 12:00:00+00', [$endsNow]);
        $this->promotion('Plan', 40, '2026-11-10 12:00:01+00', '2026-11-12 00:00:00+00', [$planned, $endsNow]);

        $promotions = new DbalActivePromotions($this->connection)->at(
            array_map(VariantId::fromString(...), [$active, $startsNow, $endsNow, $planned, $none]),
            new \DateTimeImmutable('2026-11-10T12:00:00Z'),
        );

        self::assertEqualsCanonicalizing([$active, $startsNow], array_keys($promotions));
        self::assertSame([$autumn, 'Jesień', 20, '2026-11-01T00:00:00+00:00', '2026-11-20T00:00:00+00:00'], self::describe($promotions[$active]));
        self::assertSame([$fromNow, 'Od teraz', 10, '2026-11-10T12:00:00+00:00', '2026-11-11T00:00:00+00:00'], self::describe($promotions[$startsNow]));
    }

    /**
     * Three variants with different windows in one read each. A: start 2026-11-30 12:00, window from 2026-10-31
     * 12:00; B: start 2026-11-20, window from 2026-10-21; C: no history at all.
     */
    public function testTheHistoryAndTheEarlierPromotionsOfManyVariantsInTheirOwnWindows(): void
    {
        [$a, $b, $c, $notAsked] = $this->variants(4);
        $this->history($a, '2026-10-01 10:00:00+00', 10_000);
        $this->history($a, '2026-10-15 10:00:00+00', 11_000);
        // Exactly at the start of the window, at a rate that is no longer the rate of its class.
        $this->history($a, '2026-10-31 12:00:00+00', 9_000, vatRateBp: 800);
        $this->history($a, '2026-11-30 11:59:59+00', 8_000);
        $this->history($a, '2026-11-30 12:00:00+00', 5_000);
        $this->history($b, '2026-09-01 00:00:00+00', 7_000);
        $this->history($b, '2026-10-25 00:00:00+00', 6_500);
        $this->history($notAsked, '2026-11-01 00:00:00+00', 1_000);
        $this->promotion('Przed oknem A', 50, '2026-10-01 00:00:00+00', '2026-10-31 12:00:00+00', [$a]);
        $this->promotion('W oknach A i B', 30, '2026-10-20 00:00:00+00', '2026-11-05 00:00:00+00', [$a, $b]);
        $this->promotion('W oknie B', 10, '2026-11-06 00:00:00+00', '2026-11-10 00:00:00+00', [$b]);
        $this->promotion('Bieżąca A', 20, '2026-11-30 12:00:00+00', '2026-12-10 00:00:00+00', [$a]);
        $this->promotion('Bieżąca B', 20, '2026-11-20 00:00:00+00', '2026-12-10 00:00:00+00', [$b, $c]);
        $starts = [
            $a => new \DateTimeImmutable('2026-11-30T12:00:00Z'),
            $b => new \DateTimeImmutable('2026-11-20T00:00:00Z'),
            $c => new \DateTimeImmutable('2026-11-20T00:00:00Z'),
        ];
        $omnibus = new DbalOmnibusHistory($this->connection);

        $prices = $omnibus->prices($starts);
        $promotions = $omnibus->promotions($starts);

        self::assertEqualsCanonicalizing([$a, $b], array_keys($prices));
        self::assertSame([
            ['2026-10-15T10:00:00+00:00', 11_000],
            ['2026-10-31T12:00:00+00:00', 9_000],
            ['2026-11-30T11:59:59+00:00', 8_000],
        ], array_map(self::price(...), $prices[$a]));
        self::assertSame([['2026-09-01T00:00:00+00:00', 7_000], ['2026-10-25T00:00:00+00:00', 6_500]], array_map(self::price(...), $prices[$b]));

        self::assertEqualsCanonicalizing([$a, $b], array_keys($promotions));
        self::assertSame([['2026-10-20T00:00:00+00:00', '2026-11-05T00:00:00+00:00', 30]], array_map(self::prior(...), $promotions[$a]));
        self::assertSame([
            ['2026-10-20T00:00:00+00:00', '2026-11-05T00:00:00+00:00', 30],
            ['2026-11-06T00:00:00+00:00', '2026-11-10T00:00:00+00:00', 10],
        ], array_map(self::prior(...), $promotions[$b]));
    }

    public function testOfTwoChangesInOneSecondTheLaterIsLast(): void
    {
        [$a] = $this->variants(1);
        $this->history($a, '2026-11-10 10:00:00+00', 9_000, id: '0192f0c4-7c1a-7c4e-9a6e-000000000002');
        $this->history($a, '2026-11-10 10:00:00+00', 8_000, id: '0192f0c4-7c1a-7c4e-9a6e-000000000001');

        $prices = new DbalOmnibusHistory($this->connection)->prices([$a => new \DateTimeImmutable('2026-11-20T00:00:00Z')]);

        self::assertSame([8_000, 9_000], array_map(static fn (PriorPrice $p): int => $p->gross->amount(), $prices[$a]));
    }

    /**
     * @return list<string>
     */
    private function variants(int $count): array
    {
        return array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, $count));
    }

    /**
     * @param list<string> $variantIds
     */
    private function promotion(string $name, int $percentOff, string $startsAt, string $endsAt, array $variantIds): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, '2026-09-01 00:00:00+00', '2026-09-01 00:00:00+00', 1)",
            [$id, $name, $percentOff, $startsAt, $endsAt],
        );
        foreach ($variantIds as $variantId) {
            $this->connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$id, $variantId]);
        }

        return $id;
    }

    private function history(string $variantId, string $changedAt, int $gross, int $vatRateBp = 2300, ?string $id = null): void
    {
        $this->connection->executeStatement(
            "INSERT INTO pricing_price_history (id, variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, changed_at, source) VALUES (?, ?, 'PLN', ?, 0, ?, ?, 'standard', ?, 'staff')",
            [$id ?? Uuid::v7()->toRfc4122(), $variantId, $gross, $gross, $vatRateBp, $changedAt],
        );
    }

    /**
     * @return array{string, string, int, string, string}
     */
    private static function describe(ActivePromotion $promotion): array
    {
        return [$promotion->promotionId, $promotion->name, $promotion->percentOff->value(), $promotion->period->startsAt()->format(\DATE_ATOM), $promotion->period->endsAt()->format(\DATE_ATOM)];
    }

    /**
     * @return array{string, int}
     */
    private static function price(PriorPrice $price): array
    {
        return [$price->changedAt->format(\DATE_ATOM), $price->gross->amount()];
    }

    /**
     * @return array{string, string, int}
     */
    private static function prior(PriorPromotion $promotion): array
    {
        return [$promotion->period->startsAt()->format(\DATE_ATOM), $promotion->period->endsAt()->format(\DATE_ATOM), $promotion->percentOff->value()];
    }
}
