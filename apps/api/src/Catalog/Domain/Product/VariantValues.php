<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The values of one variant, one per axis in the order of the axes; each 1 to 40 characters after trimming.
 * A default variant (a product without axes) has none.
 */
final readonly class VariantValues
{
    public const int MAX_VALUE_LENGTH = 40;

    /**
     * @param list<string> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param list<string> $values
     *
     * @throws InvalidVariantValues
     */
    public static function fromList(array $values): self
    {
        $trimmed = [];
        foreach ($values as $value) {
            $value = trim($value);
            $length = mb_strlen($value);
            if ($length < 1 || $length > self::MAX_VALUE_LENGTH) {
                throw InvalidVariantValues::lengthOutOfRange();
            }
            $trimmed[] = $value;
        }

        return new self($trimmed);
    }

    /**
     * @return list<string>
     */
    public function toList(): array
    {
        return $this->values;
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /**
     * What makes two combinations the same: the values without regard to case. mb_strtolower agrees with the
     * lower() of PostgreSQL that computes the unique index of the table, also for Polish letters.
     *
     * @return list<string>
     */
    public function comparisonKey(): array
    {
        return array_map(mb_strtolower(...), $this->values);
    }

    /**
     * The values joined with a comma; empty for a default variant.
     */
    public function label(): string
    {
        return implode(', ', $this->values);
    }
}
