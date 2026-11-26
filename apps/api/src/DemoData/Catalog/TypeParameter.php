<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

/**
 * A parameter of a product type with the values a product of it takes (fixtures/demo-data/parameters.yaml).
 */
final readonly class TypeParameter
{
    /**
     * @param non-empty-list<string> $values
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {
    }
}
