<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

/**
 * The period of a promotion, [startsAt, endsAt): from the start, until before the end. Both are whole seconds in UTC
 * (a fraction is cut), the start is before the end and the promotion lasts at most 90 days.
 */
final readonly class PromotionPeriod
{
    public const int MAX_DAYS = 90;

    private function __construct(
        private \DateTimeImmutable $startsAt,
        private \DateTimeImmutable $endsAt,
    ) {
    }

    /**
     * @throws InvalidPromotion at the end
     */
    public static function of(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): self
    {
        $startsAt = self::wholeSecond($startsAt);
        $endsAt = self::wholeSecond($endsAt);
        if ($endsAt <= $startsAt) {
            throw InvalidPromotion::endNotAfterStart();
        }
        if ($endsAt > $startsAt->modify('+'.self::MAX_DAYS.' days')) {
            throw InvalidPromotion::tooLong();
        }

        return new self($startsAt, $endsAt);
    }

    /**
     * The moment cut to its second, in UTC.
     */
    public static function wholeSecond(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$moment->getTimestamp());
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isActiveAt(\DateTimeImmutable $moment): bool
    {
        return $this->startsAt <= $moment && $moment < $this->endsAt;
    }

    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    public function equals(self $other): bool
    {
        return $this->startsAt == $other->startsAt && $this->endsAt == $other->endsAt;
    }
}
