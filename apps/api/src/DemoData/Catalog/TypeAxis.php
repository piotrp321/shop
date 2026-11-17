<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

/**
 * An axis of the variants of a product type with the values it takes.
 */
final readonly class TypeAxis
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
