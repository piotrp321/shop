<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The texts of the body that stay outside the rule of texts, each with the reason (spec:
 * docs/features/api-input-contract/spec.md, "Pola tekstowe poza regułą"): the list of exceptions of the guard of the
 * closed list of text fields (ClosedTextFieldList). A new text of the body or a new phrase `q` gets a row of the table
 * of the contract of texts (TextRows), or an entry here with the reason. The query parameters other than `q` (`path`,
 * `option`, `chunk`, `after`, `customerId`, `discountCode` of the cart) are not texts of the body: the guard of the
 * closed list of the inputs (ClosedInputList) has them.
 */
final class TextFieldExceptions
{
    /**
     * @return list<array{field: string, operations: list<string>, reason: string}> the field as the documents name it
     *                                                                               (`images[].key`), the operations
     *                                                                               ("METHOD /path") and the reason
     */
    public static function all(): array
    {
        $categories = ['POST /api/v1/admin/categories', 'PUT /api/v1/admin/categories/{id}'];
        $products = ['POST /api/v1/admin/products', 'PUT /api/v1/admin/products/{id}'];
        $deliveryMethods = ['POST /api/v1/admin/delivery-methods', 'PUT /api/v1/admin/delivery-methods/{id}'];
        $closed = 'a closed alphabet or a closed list of values, no text typed by people';

        return [
            ['field' => 'slug', 'operations' => [...$categories, ...$products], 'reason' => $closed],
            ['field' => 'taxClass', 'operations' => $products, 'reason' => $closed],
            ['field' => 'images[].key', 'operations' => $products, 'reason' => $closed],
            ['field' => 'code', 'operations' => ['POST /api/v1/admin/discount-codes'], 'reason' => 'a format with its own trimming (docs/features/discount-codes/spec.md)'],
            ['field' => 'trackingNumber', 'operations' => ['POST /api/v1/admin/orders/{orderId}/status-changes', 'PUT /api/v1/admin/orders/{orderId}/tracking-number'], 'reason' => 'a format with its own trimming (docs/features/order-management/spec.md)'],
            ['field' => 'trackingUrlTemplate', 'operations' => $deliveryMethods, 'reason' => 'a format with its own trimming (docs/features/delivery/spec.md)'],
            ['field' => 'discountCode', 'operations' => ['POST /api/v1/orders', 'POST /api/v1/me/orders'], 'reason' => 'a text empty by T3 places the order without a code and a wrong code is the rejection of the code, not an error of the input'],
        ];
    }
}
