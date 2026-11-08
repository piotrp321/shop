<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * One use of a discount code by one order (decision A1): its own aggregate, not a collection of the code, because a
 * code without a limit may have any number of them. Active until it is released (the order expired unpaid); an
 * order has at most one usage.
 */
final readonly class DiscountCodeUsage
{
    private function __construct(
        private DiscountCodeUsageId $id,
        private DiscountCodeId $codeId,
        private OrderId $orderId,
        private \DateTimeImmutable $usedAt,
        private ?\DateTimeImmutable $releasedAt,
    ) {
    }

    public static function record(DiscountCodeUsageId $id, DiscountCodeId $codeId, OrderId $orderId, \DateTimeImmutable $usedAt): self
    {
        return new self($id, $codeId, $orderId, $usedAt, null);
    }

    public static function reconstitute(DiscountCodeUsageId $id, DiscountCodeId $codeId, OrderId $orderId, \DateTimeImmutable $usedAt, ?\DateTimeImmutable $releasedAt): self
    {
        return new self($id, $codeId, $orderId, $usedAt, $releasedAt);
    }

    /**
     * The usage released at $now (the order expired unpaid, decision D4); one released already keeps its moment.
     */
    public function release(\DateTimeImmutable $now): self
    {
        return $this->isActive() ? new self($this->id, $this->codeId, $this->orderId, $this->usedAt, $now) : $this;
    }

    public function isActive(): bool
    {
        return null === $this->releasedAt;
    }

    public function id(): DiscountCodeUsageId
    {
        return $this->id;
    }

    public function codeId(): DiscountCodeId
    {
        return $this->codeId;
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function usedAt(): \DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function releasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }
}
