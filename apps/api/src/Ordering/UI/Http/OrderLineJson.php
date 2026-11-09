<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\View\OrderDiscountView;
use App\Ordering\Application\View\OrderLineView;

/**
 * The lines and the discount code of an order in the bodies of the store and the panel (schemas OrderLine,
 * OrderDiscount, BackofficeOrderLine and BackofficeOrderDiscount of nelmio_api_doc.yaml); only the panel adds the
 * promotion of a line.
 */
final class OrderLineJson
{
    /**
     * @return array<string, mixed>
     */
    public static function line(OrderLineView $line): array
    {
        return [
            'variantId' => $line->variantId,
            'productName' => $line->productName,
            'variantLabel' => $line->variantLabel,
            'quantity' => $line->quantity,
            'unitGross' => $line->unitGross,
            'grossBeforeDiscount' => $line->grossBeforeDiscount,
            'discountGross' => $line->discountGross,
            'lineGross' => $line->lineGross,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function discount(?OrderDiscountView $discount): ?array
    {
        return null === $discount ? null : [
            'code' => $discount->code,
            'kind' => $discount->kind,
            'value' => $discount->value,
            'amountGross' => $discount->amountGross,
        ];
    }
}
