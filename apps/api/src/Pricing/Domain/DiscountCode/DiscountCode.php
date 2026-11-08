<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * A code a customer types in the checkout for a discount on the whole order (decisions D1, Q3, Q4, Q7). The status is
 * not stored: disabled by its flag, otherwise planned before its start, active until before its end, expired after.
 *
 * The text never changes and a code is never deleted (disabling replaces deleting; usages point at the code). Until
 * its start a code changes in full. Once started (also disabled or expired) only its end, its flag and an existing
 * limit change: the value, the minimum and the start are fixed, so an order placed with the code always copies what
 * the code was, and a limit is not added, because placing an order locks only a code that has a limit. The limit
 * never goes below the active usages, and a changed end is after now (stopping a code at once is disabling it).
 *
 * The usages are their own aggregate (DiscountCodeUsage): a code without a limit may have any number of them, so
 * their number is asked for, never kept here. The version is the one the state will have once saved: 1 for a new
 * code, one more after a revise.
 */
final class DiscountCode
{
    private function __construct(
        private readonly DiscountCodeId $id,
        private readonly DiscountCodeValue $code,
        private DiscountValue $value,
        private ?MinimumOrder $minimumOrder,
        private ValidityPeriod $period,
        private ?UsageLimit $usageLimit,
        private bool $enabled,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?DiscountCodeActor $createdBy,
        private \DateTimeImmutable $updatedAt,
        private ?DiscountCodeActor $updatedBy,
        private int $version,
    ) {
    }

    /**
     * A new code. Its start may be in the past (the code is active at once), and so may its end (the demo data
     * create an expired code).
     *
     * @param DiscountCodeActor|null $actor null for the demo data
     *
     * @throws InvalidDiscountCode
     */
    public static function create(
        DiscountCodeId $id,
        DiscountCodeValue $code,
        DiscountValue $value,
        ?MinimumOrder $minimumOrder,
        ValidityPeriod $period,
        ?UsageLimit $usageLimit,
        bool $enabled,
        ?DiscountCodeActor $actor,
        \DateTimeImmutable $now,
    ): self {
        self::assertTerms($value, $minimumOrder);

        return new self($id, $code, $value, $minimumOrder, $period, $usageLimit, $enabled, $now, $actor, $now, $actor, 1);
    }

    /**
     * Restores a stored code.
     */
    public static function reconstitute(
        DiscountCodeId $id,
        DiscountCodeValue $code,
        DiscountValue $value,
        ?MinimumOrder $minimumOrder,
        ValidityPeriod $period,
        ?UsageLimit $usageLimit,
        bool $enabled,
        \DateTimeImmutable $createdAt,
        ?DiscountCodeActor $createdBy,
        \DateTimeImmutable $updatedAt,
        ?DiscountCodeActor $updatedBy,
        int $version,
    ): self {
        return new self($id, $code, $value, $minimumOrder, $period, $usageLimit, $enabled, $createdAt, $createdBy, $updatedAt, $updatedBy, $version);
    }

    /**
     * The one way to change a code; the whole code is given as it should be (its text excepted). Every revise
     * raises the version, also one that changes nothing.
     *
     * @param int $activeUses the active usages of the code, read under the lock of its row
     *
     * @throws InvalidDiscountCode
     * @throws DiscountCodeNotEditable
     */
    public function revise(
        DiscountValue $value,
        ?MinimumOrder $minimumOrder,
        ValidityPeriod $period,
        ?UsageLimit $usageLimit,
        bool $enabled,
        int $activeUses,
        ?DiscountCodeActor $actor,
        \DateTimeImmutable $now,
    ): void {
        self::assertTerms($value, $minimumOrder);
        if ($this->period->hasStartedAt($now)) {
            $fixedChanged = !$value->equals($this->value)
                || !self::sameMinimum($minimumOrder, $this->minimumOrder)
                || $period->startsAt() != $this->period->startsAt();
            $limitAdded = null === $this->usageLimit && null !== $usageLimit;
            if ($fixedChanged || $limitAdded) {
                throw new DiscountCodeNotEditable();
            }
        }
        $endsAt = $period->endsAt();
        if (null !== $endsAt && $endsAt != $this->period->endsAt() && $endsAt <= $now) {
            throw InvalidDiscountCode::endInThePast();
        }
        if (null !== $usageLimit && $usageLimit->value() < $activeUses) {
            throw InvalidDiscountCode::limitBelowUses($activeUses);
        }

        $this->value = $value;
        $this->minimumOrder = $minimumOrder;
        $this->period = $period;
        $this->usageLimit = $usageLimit;
        $this->enabled = $enabled;
        $this->updatedAt = $now;
        $this->updatedBy = $actor;
        ++$this->version;
    }

    /**
     * Why the code gives no discount on the subtotal (gross of the effective prices, before the code; Q2), or null
     * when it gives one. The first reason in this order: invalid (disabled, not started, expired), used up (the
     * active usages reached the limit), below the effective minimum.
     */
    public function rejectionFor(int $subtotalGross, int $activeUses, \DateTimeImmutable $now): ?DiscountCodeRejection
    {
        if (!$this->enabled || !$this->period->isValidAt($now)) {
            return DiscountCodeRejection::invalid();
        }
        if (null !== $this->usageLimit && $this->usageLimit->isReachedBy($activeUses)) {
            return DiscountCodeRejection::usedUp();
        }
        $minimum = $this->effectiveMinimumGross();
        if (null !== $minimum && $subtotalGross < $minimum) {
            return DiscountCodeRejection::belowMinimum($minimum);
        }

        return null;
    }

    /**
     * The minimum of the code and, for a percent, the implicit one of Q7 (ceil(100 / p)), whichever is larger, so an
     * applied code always gives at least one grosz. Never null for a percent code.
     */
    public function effectiveMinimumGross(): ?int
    {
        $implicit = $this->value->implicitMinimumGross();
        $explicit = $this->minimumOrder?->gross();
        if (null === $implicit) {
            return $explicit;
        }

        return max($implicit, $explicit ?? 0);
    }

    public function status(\DateTimeImmutable $now): DiscountCodeStatus
    {
        return DiscountCodeStatus::of($this->enabled, $this->period, $now);
    }

    public function id(): DiscountCodeId
    {
        return $this->id;
    }

    public function code(): DiscountCodeValue
    {
        return $this->code;
    }

    public function value(): DiscountValue
    {
        return $this->value;
    }

    public function minimumOrder(): ?MinimumOrder
    {
        return $this->minimumOrder;
    }

    public function period(): ValidityPeriod
    {
        return $this->period;
    }

    public function usageLimit(): ?UsageLimit
    {
        return $this->usageLimit;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): ?DiscountCodeActor
    {
        return $this->createdBy;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updatedBy(): ?DiscountCodeActor
    {
        return $this->updatedBy;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * D1: an amount code has a minimum above its amount, so its discount is always less than the subtotal.
     *
     * @throws InvalidDiscountCode
     */
    private static function assertTerms(DiscountValue $value, ?MinimumOrder $minimumOrder): void
    {
        $amount = $value->amountOff();
        if (null !== $amount && (null === $minimumOrder || $minimumOrder->gross() <= $amount->amount())) {
            throw InvalidDiscountCode::minimumNotAboveAmount();
        }
    }

    private static function sameMinimum(?MinimumOrder $a, ?MinimumOrder $b): bool
    {
        return null === $a || null === $b ? $a === $b : $a->equals($b);
    }
}
