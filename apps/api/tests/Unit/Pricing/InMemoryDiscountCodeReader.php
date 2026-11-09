<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\DiscountCodeReader;
use App\Pricing\Application\Port\ListedDiscountCode;
use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;

/**
 * The list of discount codes as the database gives it: newest first, then by id.
 */
final class InMemoryDiscountCodeReader implements DiscountCodeReader
{
    /** @var list<ListedDiscountCode> */
    public array $codes = [];
    /** @var list<array{DiscountCodeStatus|null, string|null, \DateTimeImmutable, DiscountCodeCursor|null, int}> */
    public array $pages = [];

    public function page(?DiscountCodeStatus $status, ?string $codePrefix, \DateTimeImmutable $now, ?DiscountCodeCursor $after, int $limit): array
    {
        $this->pages[] = [$status, $codePrefix, $now, $after, $limit];
        $codes = $this->codes;
        usort($codes, static fn (ListedDiscountCode $a, ListedDiscountCode $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);
        $codes = array_filter($codes, static fn (ListedDiscountCode $c): bool => (null === $status || DiscountCodeStatus::of($c->enabled, ValidityPeriod::of($c->startsAt, $c->endsAt), $now) === $status)
            && (null === $codePrefix || str_starts_with($c->code, $codePrefix))
            && (null === $after || $c->createdAt < $after->createdAt || ($c->createdAt == $after->createdAt && $c->id > $after->id)));

        return \array_slice(array_values($codes), 0, $limit);
    }
}
