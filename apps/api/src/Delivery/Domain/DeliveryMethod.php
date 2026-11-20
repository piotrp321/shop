<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * A way the shop delivers an order to an address, defined by the administrator (P3, P7): its name and description for
 * the customer, one fixed gross price (P5), an optional threshold of the goods from which it is free (P6), whether it is
 * offered, its position among the offered ones and an optional template of the tracking address of the carrier.
 *
 * At most MAX_ACTIVE methods are active (P16): the limit is checked against the number of active methods stored now,
 * which the caller reads without a lock (A8), when a method becomes active. A method is never deleted, it is disabled.
 * The version is the one the state will have once saved: 1 for a new method, one more after a revise. Placed orders
 * keep copies of the method, so a change never reaches them.
 */
final class DeliveryMethod
{
    public const int MAX_ACTIVE = 20;

    private function __construct(
        private readonly DeliveryMethodId $id,
        private DeliveryMethodName $name,
        private DeliveryMethodDescription $description,
        private DeliveryPrice $price,
        private ?FreeDeliveryThreshold $freeFrom,
        private bool $active,
        private DeliveryPosition $position,
        private ?TrackingUrlTemplate $trackingUrlTemplate,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private int $version,
    ) {
    }

    /**
     * @param int $activeMethods the active methods stored now
     *
     * @throws DeliveryMethodLimitReached when an active method would be one too many
     */
    public static function create(
        DeliveryMethodId $id,
        DeliveryMethodName $name,
        DeliveryMethodDescription $description,
        DeliveryPrice $price,
        ?FreeDeliveryThreshold $freeFrom,
        bool $active,
        DeliveryPosition $position,
        ?TrackingUrlTemplate $trackingUrlTemplate,
        int $activeMethods,
        \DateTimeImmutable $now,
    ): self {
        if ($active) {
            self::assertRoomForAnActiveMethod($activeMethods);
        }

        return new self($id, $name, $description, $price, $freeFrom, $active, $position, $trackingUrlTemplate, $now, $now, 1);
    }

    /**
     * Restores a stored method.
     */
    public static function reconstitute(
        DeliveryMethodId $id,
        DeliveryMethodName $name,
        DeliveryMethodDescription $description,
        DeliveryPrice $price,
        ?FreeDeliveryThreshold $freeFrom,
        bool $active,
        DeliveryPosition $position,
        ?TrackingUrlTemplate $trackingUrlTemplate,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        return new self($id, $name, $description, $price, $freeFrom, $active, $position, $trackingUrlTemplate, $createdAt, $updatedAt, $version);
    }

    /**
     * The one way to change a method; the whole method is given as it should be. Every revise raises the version,
     * also one that changes nothing.
     *
     * @param int $activeMethods the active methods stored now
     *
     * @throws DeliveryMethodLimitReached when an inactive method would become one active method too many
     */
    public function revise(
        DeliveryMethodName $name,
        DeliveryMethodDescription $description,
        DeliveryPrice $price,
        ?FreeDeliveryThreshold $freeFrom,
        bool $active,
        DeliveryPosition $position,
        ?TrackingUrlTemplate $trackingUrlTemplate,
        int $activeMethods,
        \DateTimeImmutable $now,
    ): void {
        if ($active && !$this->active) {
            self::assertRoomForAnActiveMethod($activeMethods);
        }

        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->freeFrom = $freeFrom;
        $this->active = $active;
        $this->position = $position;
        $this->trackingUrlTemplate = $trackingUrlTemplate;
        $this->updatedAt = $now;
        ++$this->version;
    }

    /**
     * The cost of the delivery of goods of this gross value after discounts (W2): nothing from the threshold
     * (inclusive), the price below it or without one.
     *
     * @throws \InvalidArgumentException for a negative value, which is a logic error of the caller
     */
    public function costFor(int $goodsGross): int
    {
        self::assertGoods($goodsGross);

        return null !== $this->freeFrom && $this->freeFrom->isReachedBy($goodsGross) ? 0 : $this->price->gross();
    }

    /**
     * What the goods lack for the method to be free, or null: without a threshold, and whenever the method costs
     * nothing (its price is 0 or the threshold is reached), so "free" never comes with a missing amount.
     *
     * @throws \InvalidArgumentException for a negative value, which is a logic error of the caller
     */
    public function missingToFreeFor(int $goodsGross): ?int
    {
        if (null === $this->freeFrom || 0 === $this->costFor($goodsGross)) {
            return null;
        }

        return $this->freeFrom->gross() - $goodsGross;
    }

    public function id(): DeliveryMethodId
    {
        return $this->id;
    }

    public function name(): DeliveryMethodName
    {
        return $this->name;
    }

    public function description(): DeliveryMethodDescription
    {
        return $this->description;
    }

    public function price(): DeliveryPrice
    {
        return $this->price;
    }

    public function freeFrom(): ?FreeDeliveryThreshold
    {
        return $this->freeFrom;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function position(): DeliveryPosition
    {
        return $this->position;
    }

    public function trackingUrlTemplate(): ?TrackingUrlTemplate
    {
        return $this->trackingUrlTemplate;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @throws DeliveryMethodLimitReached
     */
    private static function assertRoomForAnActiveMethod(int $activeMethods): void
    {
        if ($activeMethods >= self::MAX_ACTIVE) {
            throw new DeliveryMethodLimitReached();
        }
    }

    private static function assertGoods(int $goodsGross): void
    {
        if ($goodsGross < 0) {
            throw new \InvalidArgumentException('The value of the goods cannot be negative.');
        }
    }
}
