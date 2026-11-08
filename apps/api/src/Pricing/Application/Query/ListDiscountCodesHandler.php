<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\DiscountCodeReader;
use App\Pricing\Application\Port\ListedDiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListDiscountCodesHandler
{
    /** The beginning of a code (DiscountCodeValue): its characters, at most as long as a code. */
    private const string PREFIX = '/^[A-Z0-9-]{1,32}$/D';

    public function __construct(
        private DiscountCodeReader $codes,
        private Clock $clock,
    ) {
    }

    /**
     * The status filter and the status of each item are taken at one moment. The prefix is compared like a code:
     * trimmed and in upper case; one with a character no code has gives an empty page without a read. One code more
     * than the page is read: its presence is the only thing that tells there is a next page.
     *
     * @throws InvalidDiscountCodeCursor
     */
    public function __invoke(ListDiscountCodes $query): DiscountCodeList
    {
        $after = null === $query->after ? null : DiscountCodeCursor::decode($query->after);
        $prefix = null === $query->q ? null : self::prefix($query->q);
        if (false === $prefix) {
            return new DiscountCodeList([], null);
        }
        $now = $this->clock->now();

        $codes = $this->codes->page($query->status, $prefix, $now, $after, $query->perPage + 1);
        $next = null;
        if (\count($codes) > $query->perPage) {
            $codes = \array_slice($codes, 0, $query->perPage);
            $next = DiscountCodeCursor::after($codes[$query->perPage - 1])->encode();
        }

        return new DiscountCodeList(array_map(static fn (ListedDiscountCode $code): DiscountCodeView => new DiscountCodeView(
            $code->id,
            $code->code,
            $code->kind,
            $code->value,
            $code->minimumOrderGross,
            $code->startsAt,
            $code->endsAt,
            $code->usageLimit,
            $code->activeUses,
            $code->enabled,
            DiscountCodeStatus::of($code->enabled, ValidityPeriod::of($code->startsAt, $code->endsAt), $now),
            $code->version,
        ), $codes), $next);
    }

    /**
     * @return string|false the prefix in the form of a stored code, false when no code can start with it
     */
    private static function prefix(string $q): string|false
    {
        if (!mb_check_encoding($q, 'UTF-8')) {
            return false;
        }
        $prefix = strtoupper(DiscountCodeValue::trimmed($q));

        return 1 === preg_match(self::PREFIX, $prefix) ? $prefix : false;
    }
}
