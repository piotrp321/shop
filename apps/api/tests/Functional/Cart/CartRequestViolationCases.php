<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

/**
 * The invalid bodies of the cart requests with the violations they must give (spec cart-fixes, section #82): every
 * message is Polish, never carries the input, and one field gives at most one violation. Data providers for the
 * guest and the customer tests, which share the requests.
 */
trait CartRequestViolationCases
{
    private const string PRODUCT_REQUIRED = 'Identyfikator produktu jest wymagany.';
    private const string PRODUCT_UUID = 'Identyfikator produktu musi być UUID.';
    private const string QUANTITY_REQUIRED = 'Ilość jest wymagana.';
    private const string QUANTITY_INTEGER = 'Ilość musi być liczbą całkowitą.';
    private const string QUANTITY_RANGE = 'Ilość musi wynosić od 1 do 99 szt.';
    private const string CART_REQUIRED = 'Identyfikator koszyka jest wymagany.';
    private const string CART_UUID = 'Identyfikator koszyka musi być UUID.';

    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function invalidAddItemBodies(): iterable
    {
        $product = self::PRODUCT;
        yield 'no productId' => ['{"quantity": 1}', [['productId', self::PRODUCT_REQUIRED]]];
        yield 'null productId' => ['{"productId": null, "quantity": 1}', [['productId', self::PRODUCT_REQUIRED]]];
        yield 'productId a number' => ['{"productId": 5, "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'productId a fraction' => ['{"productId": 1.5, "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'productId a boolean' => ['{"productId": true, "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'productId an array' => ['{"productId": ["'.$product.'"], "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'productId an object' => ['{"productId": {"id": "'.$product.'"}, "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'productId not a uuid' => ['{"productId": "abc", "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'productId empty' => ['{"productId": "", "quantity": 1}', [['productId', self::PRODUCT_UUID]]];
        yield 'no quantity' => ['{"productId": "'.$product.'"}', [['quantity', self::QUANTITY_REQUIRED]]];
        yield 'null quantity' => ['{"productId": "'.$product.'", "quantity": null}', [['quantity', self::QUANTITY_REQUIRED]]];
        yield 'quantity in a string' => ['{"productId": "'.$product.'", "quantity": "1"}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a fraction' => ['{"productId": "'.$product.'", "quantity": 1.5}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a whole fraction' => ['{"productId": "'.$product.'", "quantity": 2.0}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a boolean' => ['{"productId": "'.$product.'", "quantity": true}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity an array' => ['{"productId": "'.$product.'", "quantity": [1]}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity 0' => ['{"productId": "'.$product.'", "quantity": 0}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'quantity 100' => ['{"productId": "'.$product.'", "quantity": 100}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'quantity negative' => ['{"productId": "'.$product.'", "quantity": -1}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'empty object' => ['{}', [['productId', self::PRODUCT_REQUIRED], ['quantity', self::QUANTITY_REQUIRED]]];
        yield 'both fields wrong' => ['{"productId": 5, "quantity": "1"}', [['productId', self::PRODUCT_UUID], ['quantity', self::QUANTITY_INTEGER]]];
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function invalidChangeQuantityBodies(): iterable
    {
        yield 'no quantity' => ['{}', [['quantity', self::QUANTITY_REQUIRED]]];
        yield 'null quantity' => ['{"quantity": null}', [['quantity', self::QUANTITY_REQUIRED]]];
        yield 'quantity in a string' => ['{"quantity": "2"}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a fraction' => ['{"quantity": 1.5}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a whole fraction' => ['{"quantity": 2.0}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a boolean' => ['{"quantity": false}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity an array' => ['{"quantity": [2]}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity 0' => ['{"quantity": 0}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'quantity 100' => ['{"quantity": 100}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'quantity negative' => ['{"quantity": -3}', [['quantity', self::QUANTITY_RANGE]]];
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function invalidMergeBodies(): iterable
    {
        $guest = self::PRODUCT;
        yield 'empty body' => ['', [['guestCartId', self::CART_REQUIRED]]];
        yield 'no guestCartId' => ['{}', [['guestCartId', self::CART_REQUIRED]]];
        yield 'null guestCartId' => ['{"guestCartId": null}', [['guestCartId', self::CART_REQUIRED]]];
        yield 'guestCartId a number' => ['{"guestCartId": 12}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId a fraction' => ['{"guestCartId": 1.5}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId a boolean' => ['{"guestCartId": true}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId an array' => ['{"guestCartId": ["'.$guest.'"]}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId an object' => ['{"guestCartId": {"id": "'.$guest.'"}}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId not a uuid' => ['{"guestCartId": "not-a-uuid"}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId empty' => ['{"guestCartId": ""}', [['guestCartId', self::CART_UUID]]];
    }
}
