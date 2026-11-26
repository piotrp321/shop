<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * The parameters of a product in presentation order: 0 to 30 pairs of a name and a value. The names differ without
 * regard to case (trimmed, as the names of the axes of the variants); the values may repeat.
 */
final readonly class ProductParameters
{
    public const int MAX_PARAMETERS = 30;

    /**
     * @param list<ProductParameter> $parameters
     */
    private function __construct(private array $parameters)
    {
    }

    /**
     * @param list<ProductParameter> $parameters
     *
     * @throws \InvalidArgumentException
     */
    public static function create(array $parameters): self
    {
        if (\count($parameters) > self::MAX_PARAMETERS) {
            throw new \InvalidArgumentException('ProductParameters must have at most '.self::MAX_PARAMETERS.' parameters.');
        }
        $names = [];
        foreach ($parameters as $parameter) {
            $key = $parameter->nameKey();
            if (isset($names[$key])) {
                throw new \InvalidArgumentException('ProductParameters must have different names.');
            }
            $names[$key] = true;
        }

        return new self($parameters);
    }

    /**
     * The parameters as stored, without the rule (spec api-input-contract, E7); only a write goes through create().
     *
     * @param list<ProductParameter> $parameters
     */
    public static function stored(array $parameters): self
    {
        return new self($parameters);
    }

    /**
     * @return list<ProductParameter> in presentation order
     */
    public function toList(): array
    {
        return $this->parameters;
    }

    public function count(): int
    {
        return \count($this->parameters);
    }

    public function isEmpty(): bool
    {
        return [] === $this->parameters;
    }
}
