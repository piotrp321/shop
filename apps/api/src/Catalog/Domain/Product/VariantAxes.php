<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The names of the axes a product varies along, in presentation order (e.g. Kolor, Rozmiar). Up to two, each
 * 1 to 30 characters after trimming Unicode whitespace, without control characters (see ProductText), without a colon
 * (it separates the axis from the value in an axis filter), different without regard to case.
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
            if (ProductText::hasForbiddenCharacters($name)) {
                throw InvalidVariantAxes::nameWithForbiddenCharacters();
            }
            $name = ProductText::trim($name);
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
