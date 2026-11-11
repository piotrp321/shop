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
    private const string VARIANT_REQUIRED = 'Identyfikator wariantu jest wymagany.';
    private const string VARIANT_UUID = 'Identyfikator wariantu musi być UUID.';
    private const string QUANTITY_REQUIRED = 'Ilość jest wymagana.';
    private const string QUANTITY_INTEGER = 'Ilość musi być liczbą całkowitą.';
    private const string QUANTITY_RANGE = 'Ilość musi wynosić od 1 do 99 szt.';
    private const string CART_REQUIRED = 'Identyfikator koszyka jest wymagany.';
    private const string CART_UUID = 'Identyfikator koszyka musi być UUID.';

    private const string VARIANT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function invalidAddItemBodies(): iterable
    {
        $variant = self::VARIANT;
        yield 'no variantId' => ['{"quantity": 1}', [['variantId', self::VARIANT_REQUIRED]]];
        yield 'null variantId' => ['{"variantId": null, "quantity": 1}', [['variantId', self::VARIANT_REQUIRED]]];
        yield 'variantId a number' => ['{"variantId": 5, "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId a fraction' => ['{"variantId": 1.5, "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId a boolean' => ['{"variantId": true, "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId an array' => ['{"variantId": ["'.$variant.'"], "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId an object' => ['{"variantId": {"id": "'.$variant.'"}, "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId not a uuid' => ['{"variantId": "abc", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId empty' => ['{"variantId": "", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId only spaces' => ['{"variantId": "   ", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId nil' => ['{"variantId": "00000000-0000-0000-0000-000000000000", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId max' => ['{"variantId": "ffffffff-ffff-ffff-ffff-ffffffffffff", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId with a wrong version and variant' => ['{"variantId": "12345678-1234-0234-0234-123456789012", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'variantId one character too long with a wrong version and variant' => ['{"variantId": "12345678-1234-0234-0234-1234567890123", "quantity": 1}', [['variantId', self::VARIANT_UUID]]];
        yield 'no quantity' => ['{"variantId": "'.$variant.'"}', [['quantity', self::QUANTITY_REQUIRED]]];
        yield 'null quantity' => ['{"variantId": "'.$variant.'", "quantity": null}', [['quantity', self::QUANTITY_REQUIRED]]];
        yield 'quantity in a string' => ['{"variantId": "'.$variant.'", "quantity": "1"}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a fraction' => ['{"variantId": "'.$variant.'", "quantity": 1.5}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a whole fraction' => ['{"variantId": "'.$variant.'", "quantity": 2.0}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity a boolean' => ['{"variantId": "'.$variant.'", "quantity": true}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity an array' => ['{"variantId": "'.$variant.'", "quantity": [1]}', [['quantity', self::QUANTITY_INTEGER]]];
        yield 'quantity 0' => ['{"variantId": "'.$variant.'", "quantity": 0}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'quantity 100' => ['{"variantId": "'.$variant.'", "quantity": 100}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'quantity negative' => ['{"variantId": "'.$variant.'", "quantity": -1}', [['quantity', self::QUANTITY_RANGE]]];
        yield 'empty object' => ['{}', [['variantId', self::VARIANT_REQUIRED], ['quantity', self::QUANTITY_REQUIRED]]];
        yield 'both fields wrong' => ['{"variantId": 5, "quantity": "1"}', [['variantId', self::VARIANT_UUID], ['quantity', self::QUANTITY_INTEGER]]];
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
        $guest = self::VARIANT;
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
        yield 'guestCartId only spaces' => ['{"guestCartId": "   "}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId nil' => ['{"guestCartId": "00000000-0000-0000-0000-000000000000"}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId max' => ['{"guestCartId": "ffffffff-ffff-ffff-ffff-ffffffffffff"}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId with a wrong version and variant' => ['{"guestCartId": "12345678-1234-0234-0234-123456789012"}', [['guestCartId', self::CART_UUID]]];
        yield 'guestCartId one character too long with a wrong version and variant' => ['{"guestCartId": "12345678-1234-0234-0234-1234567890123"}', [['guestCartId', self::CART_UUID]]];
    }
}
