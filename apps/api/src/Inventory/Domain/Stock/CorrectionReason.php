<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * Why a staff member corrected a stock.
 */
enum CorrectionReason: string
{
    case InventoryCount = 'inventory_count';
    case Damage = 'damage';
    case Loss = 'loss';
    case Other = 'other';

    /**
     * Another reason is explained in the note.
     */
    public function requiresNote(): bool
    {
        return self::Other === $this;
    }
}
