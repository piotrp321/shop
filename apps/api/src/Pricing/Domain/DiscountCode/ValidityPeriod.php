<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * When a code is valid, [startsAt, endsAt): from the start, until before the end, or for ever without an end. Both
 * are whole seconds in UTC (a fraction is cut) and the start is before the end; the length is not limited (Q4).
 */
final readonly class ValidityPeriod
{
    private function __construct(
        private \DateTimeImmutable $startsAt,
        private ?\DateTimeImmutable $endsAt,
    ) {
    }

    /**
     * @throws InvalidDiscountCode at the end
     */
    public static function of(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): self
    {
        $startsAt = self::wholeSecond($startsAt);
        $endsAt = null === $endsAt ? null : self::wholeSecond($endsAt);
        if (null !== $endsAt && $endsAt <= $startsAt) {
            throw InvalidDiscountCode::endNotAfterStart();
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

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isValidAt(\DateTimeImmutable $moment): bool
    {
        return $this->startsAt <= $moment && (null === $this->endsAt || $moment < $this->endsAt);
    }

    public function hasStartedAt(\DateTimeImmutable $moment): bool
    {
        return $this->startsAt <= $moment;
    }

    public function hasEndedAt(\DateTimeImmutable $moment): bool
    {
        return null !== $this->endsAt && $this->endsAt <= $moment;
    }

    public function equals(self $other): bool
    {
        return $this->startsAt == $other->startsAt && $this->endsAt == $other->endsAt;
    }
}
