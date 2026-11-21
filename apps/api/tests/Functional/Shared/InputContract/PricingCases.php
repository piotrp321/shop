<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Pricing\Application\Port\ListedDiscountCode;
use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Application\Query\PriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Tests\Support\InputConformance\InputValues;
use Symfony\Component\Uid\Uuid;

/**
 * Rows of the input contract of the operations of Pricing.
 */
final class PricingCases
{
    private const string PRICES = 'GET /api/v1/admin/prices';
    private const string HISTORY = 'GET /api/v1/admin/variants/{variantId}/price-history';
    private const string DISCOUNT_CODES = 'GET /api/v1/admin/discount-codes';
    private const string PROMOTIONS = 'GET /api/v1/admin/promotions';

    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        $staff = InputCase::STAFF;
        foreach (InputValues::uuidsOutsideTheVersions() as $label => [$uuid]) {
            yield InputCase::query(self::PRICES, $staff, 'product', 'productId', $label,
                'productId={productId}', 'productId='.$uuid,
                [['productId', 'Identyfikator produktu musi być poprawnym UUID.']]);
        }

        yield from ValidatorRows::category(self::PRICES, $staff);
        yield from ValidatorRows::phrase(self::PRICES, $staff);
        yield from ValidatorRows::perPage(self::PRICES, $staff, InputContractFixtures::NONE, 100);
        yield from ValidatorRows::after(self::PRICES, $staff, InputContractFixtures::NONE, CatalogCases::productCursor());
        yield ValidatorRows::ofAnotherKind(self::PRICES, $staff, InputContractFixtures::NONE, 'sort', 'sort=updatedAt', 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.');
        yield ValidatorRows::ofAnotherKind(self::PRICES, $staff, 'product', 'productId', 'productId={productId}', 'Identyfikator produktu musi być poprawnym UUID.');

        $now = new \DateTimeImmutable();
        $cursor = PriceHistoryCursor::after(new PriceHistoryItem(Uuid::v7()->toRfc4122(), $now, 'PLN', 1230, 1000, 230, 2300, 'standard', 'panel', null))->encode();
        yield from ValidatorRows::perPage(self::HISTORY, $staff, 'variant', 100);
        yield from ValidatorRows::after(self::HISTORY, $staff, 'variant', $cursor);

        $none = InputContractFixtures::NONE;
        $code = new ListedDiscountCode(Uuid::v7()->toRfc4122(), 'RABAT10', DiscountKind::Percent, 10, null, $now, null, null, true, 0, 1, $now);
        yield ValidatorRows::ofAnotherKind(self::DISCOUNT_CODES, $staff, $none, 'status', 'status=active', 'Status kodu musi być jednym z: disabled, planned, active, expired.');
        yield ValidatorRows::ofAnotherKind(self::DISCOUNT_CODES, $staff, $none, 'q', 'q=RAB', 'Początek kodu musi mieć od 1 do 32 znaków.');
        yield ValidatorRows::ofAnotherKind(self::DISCOUNT_CODES, $staff, $none, 'after', 'after='.DiscountCodeCursor::after($code)->encode(), 'Kursor stronicowania jest nieprawidłowy.');
        yield ValidatorRows::ofAnotherKind(self::DISCOUNT_CODES, $staff, $none, 'perPage', 'perPage=100', 'Liczba kodów na stronie musi być od 1 do 100.');

        // The other cases of the parameters of the list of promotions have Polish messages of their own already.
        $promotion = PromotionCursor::after(new ListedPromotion(Uuid::v7()->toRfc4122(), 'Wiosna', 10, $now, $now->modify('+7 days'), 1, 1))->encode();
        foreach (['perPage[]=x' => 'perPage=100', 'perPage=abc' => 'perPage=100', 'status[]=x' => 'status=active', 'after[]=x' => 'after='.$promotion] as $invalid => $valid) {
            $parameter = strstr($invalid, '=', true);
            $parameter = str_replace('[]', '', (string) $parameter);
            yield InputCase::query(self::PROMOTIONS, $staff, $none, $parameter, $invalid, $valid, $invalid, [[$parameter, ValidatorRows::TYPE]]);
        }
    }
}
