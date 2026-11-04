<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * One entry of the stock history: what changed (the deltas), the state right after the change, why (the reason of a
 * correction and the note), the key of the receipt or reservation, who and when. History is only ever appended to.
 */
final readonly class StockMovement
{
    public function __construct(
        private VariantId $variantId,
        private StockMovementType $type,
        private int $physicalDelta,
        private int $reservedDelta,
        private int $physicalAfter,
        private int $reservedAfter,
        private ?string $reason,
        private ?string $note,
        private ?string $reference,
        private StockChangeOrigin $origin,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public function variantId(): VariantId
    {
        return $this->variantId;
    }

    public function type(): StockMovementType
    {
        return $this->type;
    }

    public function physicalDelta(): int
    {
        return $this->physicalDelta;
    }

    public function reservedDelta(): int
    {
        return $this->reservedDelta;
    }

    public function physicalAfter(): int
    {
        return $this->physicalAfter;
    }

    public function reservedAfter(): int
    {
        return $this->reservedAfter;
    }

    /**
     * The reason of a correction; null for every other type.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /**
     * The receipt key or the reservation key.
     */
    public function reference(): ?string
    {
        return $this->reference;
    }

    public function origin(): StockChangeOrigin
    {
        return $this->origin;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Whether this is the receipt of the quantity with the note into the variant: a receipt sent again with its key
     * is the same receipt only then.
     */
    public function isReceiptOf(VariantId $variantId, int $quantity, StockNote $note): bool
    {
        return StockMovementType::Receipt === $this->type
            && $this->variantId->equals($variantId)
            && $quantity === $this->physicalDelta
            && $note->toNullableString() === $this->note;
    }
}
