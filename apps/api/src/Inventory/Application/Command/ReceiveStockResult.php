<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

final readonly class ReceiveStockResult
{
    /**
     * @param bool $repeated whether the receipt was received before with its key, so nothing changed now
     */
    public function __construct(
        public StockView $stock,
        public bool $repeated,
    ) {
    }
}
