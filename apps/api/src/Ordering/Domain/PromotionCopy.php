<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The promotion that gave the unit price of a line when the order was placed: a copy of its id, name and percent, and
 * the catalog unit gross the percent was taken off. The order keeps the copy, never a reference.
 */
final readonly class PromotionCopy
{
    public const int MAX_NAME_LENGTH = 100;

    private function __construct(
        private string $promotionId,
        private string $name,
        private int $percentOff,
        private Money $regularUnitGross,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(string $promotionId, string $name, int $percentOff, int $regularUnitGross): self
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A promotion name has 1 to %d characters.', self::MAX_NAME_LENGTH));
        }
        if ($percentOff < 1 || $percentOff > 99) {
            throw new \InvalidArgumentException('A promotion takes 1 to 99 percent off.');
        }
        if ($regularUnitGross < 1) {
            throw new \InvalidArgumentException('A catalog price is at least one grosz.');
        }

        return new self(UuidFormat::normalise($promotionId, 'A promotion id'), $name, $percentOff, Money::of($regularUnitGross, Currency::PLN));
    }

    public function promotionId(): string
    {
        return $this->promotionId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function percentOff(): int
    {
        return $this->percentOff;
    }

    public function regularUnitGross(): Money
    {
        return $this->regularUnitGross;
    }
}
