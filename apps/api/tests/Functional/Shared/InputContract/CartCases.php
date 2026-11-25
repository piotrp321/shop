<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the input contract of the operations of Cart.
 */
final class CartCases
{
    private const string PRODUCT_OFFERS = 'GET /api/v1/product-offers';

    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        $offers = [
            'no productId' => ['', [['productId', ValidatorRows::AT_LEAST_ONE]]],
            'empty element' => ['productId=', [['productId[0]', ValidatorRows::BLANK]]],
            'productId=abc' => ['productId=abc', [['productId[0]', ValidatorRows::UUID]]],
        ];
        foreach ($offers as $label => [$invalid, $violations]) {
            yield InputCase::query(self::PRODUCT_OFFERS, null, 'product', 'productId', $label, 'productId={productId}', $invalid, $violations);
        }

        $discountCode = 'Kod rabatowy musi być tekstem.';
        yield ValidatorRows::ofAnotherKind('GET /api/v1/guest-carts/{cartId}', null, 'guestCart', 'discountCode', 'discountCode=RABAT10', $discountCode);
        yield ValidatorRows::ofAnotherKind('GET /api/v1/me/cart', InputCase::CUSTOMER, InputContractFixtures::NONE, 'discountCode', 'discountCode=RABAT10', $discountCode);
    }
}
