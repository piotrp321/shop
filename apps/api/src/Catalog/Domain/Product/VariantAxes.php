<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Text;

/**
 * The names of the axes a product varies along, in presentation order (e.g. Kolor, Rozmiar). Up to two, each a text by
 * the rule of texts (Text) of 1 to 30 characters once trimmed, without a colon (it separates the axis from the value
 * in an axis filter), different without regard to case.
 */
final readonly class VariantAxes
{
    public const int MAX_AXES = 2;
    public const int MAX_NAME_LENGTH = 30;

    /**
     * @param list<string> $names
     */
    private function __construct(private array $names)
    {
    }

    /**
     * @param list<string> $names
     *
     * @throws InvalidVariantAxes
     */
    public static function fromList(array $names): self
    {
        if (\count($names) > self::MAX_AXES) {
            throw InvalidVariantAxes::tooMany();
        }

        $trimmed = [];
        $seen = [];
        foreach ($names as $name) {
            if (Text::hasForbiddenCharacters($name)) {
                throw InvalidVariantAxes::nameWithForbiddenCharacters();
            }
            $name = Text::normalize($name);
            $length = mb_strlen($name);
            if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
                throw InvalidVariantAxes::nameLengthOutOfRange();
            }
            if (str_contains($name, ':')) {
                throw InvalidVariantAxes::nameWithColon();
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw InvalidVariantAxes::nameRepeated();
            }
            $seen[$key] = true;
            $trimmed[] = $name;
        }

        return new self($trimmed);
    }

    /**
     * The names as stored, without the rule (spec api-input-contract, E7); only a write goes through fromList().
     *
     * @param list<string> $names
     */
    public static function stored(array $names): self
    {
        return new self($names);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function count(): int
    {
        return \count($this->names);
    }
}
