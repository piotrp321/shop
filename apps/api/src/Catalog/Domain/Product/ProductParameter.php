<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * One parameter of a product, a pair of one-line texts by the rule of texts (Text): the name of 1 to 60 and the value of
 * 1 to 200 characters once trimmed.
 */
final readonly class ProductParameter
{
    public const int MAX_NAME_LENGTH = 60;
    public const int MAX_VALUE_LENGTH = 200;

    private function __construct(
        private string $name,
        private string $value,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function of(string $name, string $value): self
    {
        return new self(
            self::text($name, 'name', self::MAX_NAME_LENGTH),
            self::text($value, 'value', self::MAX_VALUE_LENGTH),
        );
    }

    /**
     * A parameter as stored, without the rule (spec api-input-contract, E7); only a write goes through of().
     */
    public static function stored(string $name, string $value): self
    {
        return new self($name, $value);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The comparison key of a name: the trimmed name without regard to case, as the axes of the variants are compared.
     */
    public function nameKey(): string
    {
        return self::keyOfName($this->name);
    }

    /**
     * The same key for a name that is not (yet) a parameter, e.g. one of a definition of the demo data.
     */
    public static function keyOfName(string $name): string
    {
        return mb_strtolower(Text::normalize($name));
    }

    private static function text(string $text, string $field, int $maxLength): string
    {
        if (Text::hasForbiddenCharacters($text)) {
            throw new \InvalidArgumentException(\sprintf('ProductParameter %s must not contain forbidden characters.', $field));
        }
        $text = Text::normalize($text);
        $length = mb_strlen($text);
        if ($length < 1 || $length > $maxLength) {
            throw new \InvalidArgumentException(\sprintf('ProductParameter %s must have from 1 to %d characters.', $field, $maxLength));
        }

        return $text;
    }
}
