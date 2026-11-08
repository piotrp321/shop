<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\TaxClassView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A tax class a product can have.', required: ['code', 'name'])]
final readonly class TaxClassResponse
{
    public function __construct(
        #[OA\Property(description: 'Code to send as `taxClass`.', example: 'printed_book')]
        public string $code,
        #[OA\Property(description: 'Polish name for the form.', example: 'Książki drukowane')]
        public string $name,
    ) {
    }

    public static function of(TaxClassView $class): self
    {
        return new self($class->code, $class->name);
    }
}
