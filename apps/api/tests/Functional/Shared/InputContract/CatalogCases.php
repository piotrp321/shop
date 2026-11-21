<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Application\Query\SitemapChunkKey;
use Symfony\Component\Uid\Uuid;

/**
 * Rows of the input contract of the operations of Catalog.
 */
final class CatalogCases
{
    private const string PRODUCTS = 'GET /api/v1/products';
    private const string BACKOFFICE_PRODUCTS = 'GET /api/v1/admin/products';

    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        yield from self::products();
        yield from self::backofficeProducts();

        yield ValidatorRows::ofAnotherKind('GET /api/v1/categories/redirect', null, 'categoryRedirect', 'path', 'path={path}', 'Adres kategorii to od 1 do 4 slugów rozdzielonych ukośnikiem.');
        yield ValidatorRows::ofAnotherKind('GET /api/v1/sitemap/products', null, InputContractFixtures::NONE, 'chunk', 'chunk='.SitemapChunkKey::between(null, null)->encode(), 'Klucz porcji jest niepoprawny.');
        yield ValidatorRows::ofAnotherKind('GET /api/v1/admin/categories', InputCase::STAFF, InputContractFixtures::NONE, 'withCounts', 'withCounts=true', 'Parametr withCounts przyjmuje tylko wartość true.');
        yield ValidatorRows::ofAnotherKind('DELETE /api/v1/admin/categories/{id}', InputCase::STAFF, 'category', 'version', 'version=1', 'Wersja musi być dodatnią liczbą całkowitą.');
    }

    /**
     * A cursor of the lists of products of the panel (also behind the lists of stocks and prices) in the default sort.
     */
    public static function productCursor(): string
    {
        return ProductCursor::after(new BackofficeProduct(Uuid::v4()->toRfc4122(), 'Namiot', 'standard', new \DateTimeImmutable()), ProductSort::Name)->encode();
    }

    /**
     * @return iterable<InputCase>
     */
    private static function products(): iterable
    {
        yield from ValidatorRows::category(self::PRODUCTS, null);
        yield from ValidatorRows::phrase(self::PRODUCTS, null);
        yield from ValidatorRows::perPage(self::PRODUCTS, null, InputContractFixtures::NONE, 48);

        foreach (['priceMin', 'priceMax'] as $price) {
            foreach ([$price.'[]=x' => ValidatorRows::TYPE, $price.'=' => ValidatorRows::BLANK] as $invalid => $title) {
                yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, $price, $invalid, $price.'=1000', $invalid, [[$price, $title]]);
            }
        }
        foreach (['available[]=x' => ValidatorRows::TYPE, 'available=false' => ValidatorRows::CHOICE] as $invalid => $title) {
            yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'available', $invalid, 'available=true', $invalid, [['available', $title]]);
        }
        foreach (['sort[]=x' => ValidatorRows::TYPE, 'sort=cheapest' => ValidatorRows::CHOICE] as $invalid => $title) {
            yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'sort', $invalid, 'sort=price_asc', $invalid, [['sort', $title]]);
        }
        $pages = [
            'page=abc' => ValidatorRows::TYPE,
            'page=' => ValidatorRows::TYPE,
            'page[]=x' => ValidatorRows::TYPE,
            'page=0' => ValidatorRows::POSITIVE,
            'page=-1' => ValidatorRows::POSITIVE,
        ];
        foreach ($pages as $invalid => $title) {
            yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'page', $invalid, 'page=2', $invalid, [['page', $title]]);
        }
    }

    /**
     * @return iterable<InputCase>
     */
    private static function backofficeProducts(): iterable
    {
        $staff = InputCase::STAFF;
        yield from ValidatorRows::category(self::BACKOFFICE_PRODUCTS, $staff);
        yield from ValidatorRows::phrase(self::BACKOFFICE_PRODUCTS, $staff);
        yield from ValidatorRows::perPage(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 100);
        yield from ValidatorRows::after(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, self::productCursor());
        yield InputCase::query(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'status', 'status[]=x', 'status=draft', 'status[]=x', [['status', ValidatorRows::TYPE]]);

        yield ValidatorRows::ofAnotherKind(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'sort', 'sort=-name', 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.');
        yield ValidatorRows::ofAnotherKind(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'images', 'images=none', 'Filtr zdjęć może mieć tylko wartość none.');
    }
}
