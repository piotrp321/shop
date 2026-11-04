<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\VariantId;

final readonly class DirectoryVariant
{
    /**
     * @param string $label the values of the variant joined by ", ", empty for the default variant
     */
    public function __construct(
        public VariantId $id,
        public string $label,
    ) {
    }
}
