<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ProductParameterView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A parameter of a product in the edit view: a name and a value.', required: ['name', 'value'])]
final readonly class ProductParameterResponse
{
    public function __construct(
        #[OA\Property(example: 'Liczba osób')]
        public string $name,
        #[OA\Property(example: '4')]
        public string $value,
    ) {
    }

    public static function of(ProductParameterView $parameter): self
    {
        return new self($parameter->name, $parameter->value);
    }
}
