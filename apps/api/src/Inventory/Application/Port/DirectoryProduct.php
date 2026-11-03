<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\ProductId;

final readonly class DirectoryProduct
{
    public function __construct(
        public ProductId $id,
        public string $name,
    ) {
    }
}
