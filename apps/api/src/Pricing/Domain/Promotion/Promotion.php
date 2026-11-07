<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Price\VariantId;

/**
 * A planned discount of a percent on a list of variants for a period (decisions Q2, Q3, Q5). The status is not stored,
 * it follows from the period and the moment: planned before the start, active until before the end, ended after.
 *
 * A promotion never changes its past: once started only the name and the end change, and the end never moves before
 * now; an ended promotion changes no more, and only a planned one may be deleted. So the stored promotions are a
 * reliable history of the prices that were in force.
 *
 * Every moment of a promotion is a whole second. A start at or before now becomes the start of now (now cut to its
 * second + 1 s): the history of the prices keeps seconds, so a price written before a promotion "from now" is always
 * before its start. The version is the one the state will have once saved: 1 for a new promotion, one more after a
 * revise.
 *
 * That two promotions with a common variant never overlap (Q4) is a rule across promotions; the handlers of the
 * writes check it under the lock of all promotion writes.
 */
final class Promotion
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MIN_VARIANTS = 1;
    public const int MAX_VARIANTS = 1000;

    /**
     * @param list<VariantId> $variantIds
     */
    private function __construct(
        private readonly PromotionId $id,
        private string $name,
        private PercentOff $percentOff,
        private PromotionPeriod $period,
        private array $variantIds,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?PromotionActor $createdBy,
        private \DateTimeImmutable $updatedAt,
        private ?PromotionActor $updatedBy,
        private int $version,
    ) {
    }

    /**
     * A new promotion. A start that is null or at or before now is the start of now ("from now").
     *
     * @param list<VariantId>     $variantIds
     * @param PromotionActor|null $actor      null for the demo data
     *
     * @throws InvalidPromotion
     */
    public static function plan(
        PromotionId $id,
        string $name,
        PercentOff $percentOff,
        ?\DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        array $variantIds,
        ?PromotionActor $actor,
        \DateTimeImmutable $now,
    ): self {
        $name = self::validName($name);
        $variantIds = self::validVariants($variantIds);
        $period = self::plannedPeriod($startsAt, $endsAt, $now);

        return new self($id, $name, $percentOff, $period, $variantIds, $now, $actor, $now, $actor, 1);
    }

    /**
     * Restores a stored promotion.
     *
     * @param list<VariantId> $variantIds
     */
    public static function reconstitute(
        PromotionId $id,
        string $name,
        PercentOff $percentOff,
        PromotionPeriod $period,
        array $variantIds,
        \DateTimeImmutable $createdAt,
        ?PromotionActor $createdBy,
        \DateTimeImmutable $updatedAt,
        ?PromotionActor $updatedBy,
        int $version,
    ): self {
        return new self($id, $name, $percentOff, $period, $variantIds, $createdAt, $createdBy, $updatedAt, $updatedBy, $version);
    }

    /**
     * Now cut to its second, plus one second: the first whole second after now.
     */
    public static function startOfNow(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.($now->getTimestamp() + 1));
    }

    /**
     * The one way to change a promotion; what may change depends on its status at now. A planned promotion changes
     * everything (a start at or before now becomes the start of now). An active one changes only its name and end:
     * any other value different from the stored one is refused, and an end at or before now ends it at the start of
     * now ("end now"). An ended one changes no more.
     *
     * @param list<VariantId> $variantIds
     *
     * @return bool whether the period changed
     *
     * @throws PromotionNotEditable
     * @throws InvalidPromotion
     */
    public function revise(
        string $name,
        PercentOff $percentOff,
        ?\DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        array $variantIds,
        ?PromotionActor $actor,
        \DateTimeImmutable $now,
    ): bool {
        $status = $this->status($now);
        if (PromotionStatus::Ended === $status) {
            throw PromotionNotEditable::ended();
        }

        $name = self::validName($name);
        $variantIds = self::validVariants($variantIds);
        if (PromotionStatus::Planned === $status) {
            $period = self::plannedPeriod($startsAt, $endsAt, $now);
            $this->percentOff = $percentOff;
            $this->variantIds = $variantIds;
        } else {
            $sameStart = null !== $startsAt && PromotionPeriod::wholeSecond($startsAt) == $this->period->startsAt();
            if (!$sameStart || !$percentOff->equals($this->percentOff) || !$this->hasExactly($variantIds)) {
                throw PromotionNotEditable::afterStart();
            }
            $end = PromotionPeriod::wholeSecond($endsAt) <= $now ? self::startOfNow($now) : $endsAt;
            $period = PromotionPeriod::of($this->period->startsAt(), $end);
        }

        $periodChanged = !$period->equals($this->period);
        $this->name = $name;
        $this->period = $period;
        $this->updatedAt = $now;
        $this->updatedBy = $actor;
        ++$this->version;

        return $periodChanged;
    }

    /**
     * @throws PromotionNotEditable unless the promotion is planned
     */
    public function assertDeletable(\DateTimeImmutable $now): void
    {
        if (PromotionStatus::Planned !== $this->status($now)) {
            throw PromotionNotEditable::notDeletable();
        }
    }

    /**
     * @param list<VariantId> $variantIds
     *
     * @return list<VariantId> the ones that are not in the promotion, in the order given
     */
    public function newVariantsAmong(array $variantIds): array
    {
        $current = $this->variantKeys();

        return array_values(array_filter($variantIds, static fn (VariantId $id): bool => !isset($current[$id->toString()])));
    }

    public function status(\DateTimeImmutable $now): PromotionStatus
    {
        return PromotionStatus::of($this->period, $now);
    }

    public function id(): PromotionId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function percentOff(): PercentOff
    {
        return $this->percentOff;
    }

    public function period(): PromotionPeriod
    {
        return $this->period;
    }

    /**
     * @return list<VariantId>
     */
    public function variantIds(): array
    {
        return $this->variantIds;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): ?PromotionActor
    {
        return $this->createdBy;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updatedBy(): ?PromotionActor
    {
        return $this->updatedBy;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @throws InvalidPromotion
     */
    private static function plannedPeriod(?\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): PromotionPeriod
    {
        if (PromotionPeriod::wholeSecond($endsAt) <= $now) {
            throw InvalidPromotion::endInThePast();
        }
        if (null === $startsAt || PromotionPeriod::wholeSecond($startsAt) <= $now) {
            $startsAt = self::startOfNow($now);
        }

        return PromotionPeriod::of($startsAt, $endsAt);
    }

    /**
     * @throws InvalidPromotion
     */
    private static function validName(string $name): string
    {
        $name = trim($name);
        // The encoding first: a pattern with /u does not match invalid UTF-8 at all.
        if ('' === $name || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::MAX_NAME_LENGTH || 1 === preg_match('/\p{Cc}/u', $name)) {
            throw InvalidPromotion::name();
        }

        return $name;
    }

    /**
     * @param list<VariantId> $variantIds
     *
     * @return list<VariantId>
     *
     * @throws InvalidPromotion
     */
    private static function validVariants(array $variantIds): array
    {
        $count = \count($variantIds);
        if ($count < self::MIN_VARIANTS || $count > self::MAX_VARIANTS) {
            throw InvalidPromotion::variantCount();
        }
        $unique = [];
        foreach ($variantIds as $id) {
            $unique[$id->toString()] = true;
        }
        if (\count($unique) !== $count) {
            throw InvalidPromotion::repeatedVariant();
        }

        return $variantIds;
    }

    /**
     * @param list<VariantId> $variantIds
     */
    private function hasExactly(array $variantIds): bool
    {
        $given = [];
        foreach ($variantIds as $id) {
            $given[$id->toString()] = true;
        }

        return $given == $this->variantKeys();
    }

    /**
     * @return array<string, true>
     */
    private function variantKeys(): array
    {
        $keys = [];
        foreach ($this->variantIds as $id) {
            $keys[$id->toString()] = true;
        }

        return $keys;
    }
}
