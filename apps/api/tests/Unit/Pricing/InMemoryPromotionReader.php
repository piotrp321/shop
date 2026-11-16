<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Port\PromotionReader;
use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Shared\Domain\BoundedCount;

/**
 * The list of promotions as the database gives it: newest start first, then by id.
 */
final class InMemoryPromotionReader implements PromotionReader
{
    /** @var list<ListedPromotion> */
    public array $promotions = [];
    /** @var list<array{PromotionStatus|null, \DateTimeImmutable, PromotionCursor|null, int}> */
    public array $pages = [];
    /** @var list<array{PromotionStatus|null, \DateTimeImmutable}> */
    public array $counts = [];
    public BoundedCount $total;

    public function __construct()
    {
        $this->total = new BoundedCount(0, false);
    }

    public function page(?PromotionStatus $status, \DateTimeImmutable $now, ?PromotionCursor $after, int $limit): array
    {
        $this->pages[] = [$status, $now, $after, $limit];
        $promotions = $this->promotions;
        usort($promotions, static fn (ListedPromotion $a, ListedPromotion $b): int => [$b->startsAt, $a->id] <=> [$a->startsAt, $b->id]);
        $promotions = array_filter($promotions, static fn (ListedPromotion $p): bool => (null === $status || PromotionStatus::of(PromotionPeriod::of($p->startsAt, $p->endsAt), $now) === $status)
            && (null === $after || $p->startsAt < $after->startsAt || ($p->startsAt == $after->startsAt && $p->id > $after->id)));

        return \array_slice(array_values($promotions), 0, $limit);
    }

    public function count(?PromotionStatus $status, \DateTimeImmutable $now): BoundedCount
    {
        $this->counts[] = [$status, $now];

        return $this->total;
    }
}
