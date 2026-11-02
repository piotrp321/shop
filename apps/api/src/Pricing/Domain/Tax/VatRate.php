<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Tax;

/**
 * A VAT rate in basis points: 2300 is 23%. Integer only, from 0 to 10000 (100%).
 */
final readonly class VatRate
{
    private const int MAX_BASIS_POINTS = 10000;

    private function __construct(private int $basisPoints)
    {
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new \InvalidArgumentException('A VAT rate must be between 0 and 10000 basis points.');
        }

        return new self($basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }
}
