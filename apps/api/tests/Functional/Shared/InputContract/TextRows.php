<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The whole table of the contract of texts: the rows of every module, the one list the contract test of texts reads.
 */
final class TextRows
{
    /**
     * @return list<TextRow>
     */
    public static function all(): array
    {
        $rows = [];
        foreach ([CatalogTextRows::all(), DeliveryTextRows::all(), InventoryTextRows::all(), OrderingTextRows::all(), PricingTextRows::all()] as $module) {
            foreach ($module as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
