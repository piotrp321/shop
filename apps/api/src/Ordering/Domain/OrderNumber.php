<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The number a customer sees: `R-` and a value of the number sequence, which starts at 100001.
 */
final readonly class OrderNumber
{
    public const int FIRST = 100001;
    private const string PATTERN = '/^R-([1-9][0-9]{5,17})$/D';

    private function __construct(private int $value)
    {
        if ($value < self::FIRST) {
            throw new \InvalidArgumentException(\sprintf('An order number starts at %d.', self::FIRST));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromSequence(int $value): self
    {
        return new self($value);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $number): self
    {
        if (1 !== preg_match(self::PATTERN, $number, $matches)) {
            throw new \InvalidArgumentException('An order number is R- and a number.');
        }

        return new self((int) $matches[1]);
    }

    public function toString(): string
    {
        return 'R-'.$this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
