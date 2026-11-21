<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Inventory\Application\Query\StockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use App\Tests\Support\InputConformance\InputValues;
use Symfony\Component\Uid\Uuid;

/**
 * Rows of the input contract of the operations of Inventory.
 */
final class InventoryCases
{
    private const string STOCK = 'GET /api/v1/admin/stock';
    private const string HISTORY = 'GET /api/v1/admin/variants/{variantId}/stock-history';
    private const string BAD_CURSOR = 'Kursor stronicowania jest nieprawidłowy.';

    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        $staff = InputCase::STAFF;
        foreach (InputValues::uuidsOutsideTheVersions() as $label => [$uuid]) {
            yield InputCase::query(self::STOCK, $staff, 'product', 'productId', $label,
                'productId={productId}', 'productId='.$uuid,
                [['productId', 'Identyfikator produktu musi być poprawnym UUID.']]);
        }

        yield from ValidatorRows::category(self::STOCK, $staff);
        yield from ValidatorRows::phrase(self::STOCK, $staff);
        yield from ValidatorRows::perPage(self::STOCK, $staff, InputContractFixtures::NONE, 100);
        yield from ValidatorRows::after(self::STOCK, $staff, InputContractFixtures::NONE, CatalogCases::productCursor());
        yield InputCase::query(self::STOCK, $staff, InputContractFixtures::NONE, 'after', 'after=abc', 'after='.CatalogCases::productCursor(), 'after=abc', [['after', self::BAD_CURSOR]]);
        yield InputCase::query(self::STOCK, $staff, 'product', 'q', 'q=ab%00', 'q=namiot', 'q=ab%00', [['q', 'Szukana fraza zawiera niedozwolone znaki.']]);
        yield ValidatorRows::ofAnotherKind(self::STOCK, $staff, InputContractFixtures::NONE, 'sort', 'sort=-updatedAt', 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.');
        yield ValidatorRows::ofAnotherKind(self::STOCK, $staff, InputContractFixtures::NONE, 'level', 'level=low_stock', 'Poziom stanu musi być jednym z: out_of_stock, low_stock.');
        yield ValidatorRows::ofAnotherKind(self::STOCK, $staff, 'product', 'productId', 'productId={productId}', 'Identyfikator produktu musi być poprawnym UUID.');

        $cursor = StockHistoryCursor::after(new StockHistoryItem(Uuid::v7()->toRfc4122(), new \DateTimeImmutable(), 'receipt', 1, 0, 1, 0, null, null, null, 'panel', null))->encode();
        yield from ValidatorRows::perPage(self::HISTORY, $staff, 'variant', 100);
        yield from ValidatorRows::after(self::HISTORY, $staff, 'variant', $cursor);
        yield InputCase::query(self::HISTORY, $staff, 'variant', 'after', 'after=abc', 'after='.$cursor, 'after=abc', [['after', self::BAD_CURSOR]]);

        yield from IdempotencyKeyRows::of('POST /api/v1/admin/variants/{variantId}/stock/receipts', $staff, 'variant', '{"quantity":5,"note":null}');
    }
}
