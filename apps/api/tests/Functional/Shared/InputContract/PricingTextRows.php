<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the contract of texts of the operations of Pricing (TextRow): the name of a promotion in its creation and its
 * change, and the phrases of the lists of prices and of discount codes (the beginning of a code).
 */
final class PricingTextRows
{
    private const string PLAN = 'POST /api/v1/admin/promotions';
    private const string REVISE = 'PUT /api/v1/admin/promotions/{id}';
    private const string NAME = 'Nazwa promocji musi mieć od 1 do 100 znaków, bez znaków sterujących.';
    private const string PREFIX = 'Początek kodu musi mieć od 1 do 32 znaków.';

    /**
     * @return iterable<TextRow>
     */
    public static function all(): iterable
    {
        $staff = InputCase::STAFF;
        // One variant of the fixture, at a price; the promotion ends in five days.
        $promotion = [
            'name' => 'Jesień w górach',
            'percentOff' => 20,
            'startsAt' => null,
            'endsAt' => new \DateTimeImmutable('+5 days')->format(\DATE_RFC3339),
            'variantIds' => ['{variantId}'],
        ];

        yield TextRow::required(self::PLAN, $staff, 'variant', 'name', 'Jesień w górach', $promotion,
            empty: self::NAME,
            forbidden: self::NAME,
            wrongType: 'Nazwa promocji musi być tekstem.',
            stored: TextRow::column('SELECT name FROM pricing_promotion WHERE id = :id', ['id' => '{response.id}']));
        yield TextRow::required(self::REVISE, $staff, 'promotion', 'name', 'Jesień w górach', [...$promotion, 'version' => 1],
            empty: self::NAME,
            forbidden: self::NAME,
            wrongType: 'Nazwa promocji musi być tekstem.',
            stored: TextRow::column('SELECT name FROM pricing_promotion WHERE id = :id', ['id' => '{id}']));

        yield TextRow::phrase('GET /api/v1/admin/prices', $staff, 'indexedProduct', 'namiot', ValidatorRows::tooShort(2), 'Szukana fraza zawiera niedozwolone znaki.',
            phrase: '{productName}', record: '{productId}', items: 'items', id: 'productId', count: 'total');
        yield TextRow::phrase('GET /api/v1/admin/discount-codes', $staff, 'discountCode', 'RABAT', self::PREFIX, self::PREFIX,
            phrase: '{code}', record: '{id}', items: 'items', id: 'id', count: 'total');
    }
}
