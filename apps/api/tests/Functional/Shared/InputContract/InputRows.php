<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The whole table of the input contract: the rows of every module, the one list the contract test and the guard of the
 * closed list read.
 */
final class InputRows
{
    /**
     * @return list<InputCase>
     */
    public static function all(): array
    {
        $rows = [];
        foreach ([CartCases::all(), CatalogCases::all(), InventoryCases::all(), OrderingCases::all(), PaymentCases::all(), PricingCases::all()] as $module) {
            foreach ($module as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
