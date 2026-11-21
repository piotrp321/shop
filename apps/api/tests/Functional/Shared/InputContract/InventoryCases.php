<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Tests\Support\InputConformance\InputValues;

/**
 * Rows of the input contract of the operations of Inventory.
 */
final class InventoryCases
{
    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        foreach (InputValues::uuidsOutsideTheVersions() as $label => [$uuid]) {
            yield InputCase::query('GET /api/v1/admin/stock', InputCase::STAFF, 'product', 'productId', $label,
                'productId={productId}', 'productId='.$uuid,
                [['productId', 'Identyfikator produktu musi być poprawnym UUID.']]);
        }
    }
}
