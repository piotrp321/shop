<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The values of one variant, one per axis in the order of the axes; each a text by the rule of texts (Text) of 1 to 40
 * characters once trimmed. A default variant (a product without axes) has none.
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
            if (Text::hasForbiddenCharacters($value)) {
                throw InvalidVariantValues::forbiddenCharacters();
            }
            $value = Text::normalize($value);
            $length = mb_strlen($value);
            if ($length < 1 || $length > self::MAX_VALUE_LENGTH) {
                throw InvalidVariantValues::lengthOutOfRange();
            }
            $trimmed[] = $value;
        }

        return new self($trimmed);
    }

    /**
     * The values as stored, without the rule (spec api-input-contract, E7); only a write goes through fromList().
     *
     * @param list<string> $values
     */
    public static function reconstitute(array $values): self
    {
        return new self($values);
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
     * What makes two combinations the same: the values without regard to case (mb_strtolower, also for Polish
     * letters). The unique index of the variants table is built from this key too, not from lower() of PostgreSQL,
     * which treats some letters differently (e.g. "İ" and a final "Σ").
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
