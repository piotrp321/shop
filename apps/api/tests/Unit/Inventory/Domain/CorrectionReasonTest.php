<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Stock\CorrectionReason;
use PHPUnit\Framework\TestCase;

final class CorrectionReasonTest extends TestCase
{
    public function testTheReasonsOfACorrection(): void
    {
        self::assertSame(
            [CorrectionReason::InventoryCount, CorrectionReason::Damage, CorrectionReason::Loss, CorrectionReason::Other],
            array_map(CorrectionReason::from(...), ['inventory_count', 'damage', 'loss', 'other']),
        );
    }

    public function testOnlyOtherNeedsANote(): void
    {
        self::assertSame(
            ['inventory_count' => false, 'damage' => false, 'loss' => false, 'other' => true],
            array_combine(
                array_map(static fn (CorrectionReason $reason): string => $reason->value, CorrectionReason::cases()),
                array_map(static fn (CorrectionReason $reason): bool => $reason->requiresNote(), CorrectionReason::cases()),
            ),
        );
    }
}
