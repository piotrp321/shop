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
    private const string BAD_PRICE = 'Cena musi być liczbą całkowitą groszy, 0 albo więcej.';
    private const string BAD_OPTION_LENGTH = 'Oś opcji ma od 1 do 30 znaków, a wartość od 1 do 40.';

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
        foreach (['abc', '-1', '1.5'] as $value) {
            foreach (['priceMin', 'priceMax'] as $price) {
                yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, $price, $price.'='.$value, $price.'=0', $price.'='.$value, [[$price, self::BAD_PRICE]]);
            }
        }
        yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'priceMin', 'priceMin above priceMax', 'priceMin=5&priceMax=5', 'priceMin=6&priceMax=5', [['priceMin', 'Cena minimalna nie może być wyższa niż maksymalna.']]);
        foreach (['available[]=x' => ValidatorRows::TYPE, 'available=false' => ValidatorRows::CHOICE] as $invalid => $title) {
            yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'available', $invalid, 'available=true', $invalid, [['available', $title]]);
        }
        foreach (['sort[]=x' => ValidatorRows::TYPE, 'sort=cheapest' => ValidatorRows::CHOICE] as $invalid => $title) {
            yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'sort', $invalid, 'sort=price_asc', $invalid, [['sort', $title]]);
        }
        yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'sort', 'relevance without q', 'sort=relevance&q=namiot', 'sort=relevance', [['sort', 'To sortowanie wymaga szukanej frazy.']]);
        yield from self::options();
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
     * The repeated `option` key of the list of products: each invalid value has a valid one at the same limit.
     *
     * @return iterable<InputCase>
     */
    private static function options(): iterable
    {
        $cases = [
            'option[]=x' => ['option=Kolor%3Aczarne', 'option%5B%5D=Kolor%3Aczarne', 'Podaj opcje jako powtórzony klucz option, nie option[].'],
            'without a colon' => ['option=Kolor%3Aczarne', 'option=Kolor', 'Opcja musi mieć postać oś:wartość.'],
            'invalid UTF-8' => ['option=Kolor%3Aczarne', 'option=Kolor%3A%FF', 'Opcja zawiera niedozwolone znaki.'],
            'axis over 30 characters' => ['option='.str_repeat('a', 30).'%3Ax', 'option='.str_repeat('a', 31).'%3Ax', self::BAD_OPTION_LENGTH],
            'value over 40 characters' => ['option=Kolor%3A'.str_repeat('a', 40), 'option=Kolor%3A'.str_repeat('a', 41), self::BAD_OPTION_LENGTH],
            '51 options' => [
                self::optionEntries(50, static fn (int $i): string => 'Kolor%3A'.$i),
                self::optionEntries(51, static fn (int $i): string => 'Kolor%3A'.$i),
                'Można podać najwyżej 50 opcji.',
            ],
            'options of 6 axes' => [
                self::optionEntries(5, static fn (int $i): string => 'Os'.$i.'%3Ax'),
                self::optionEntries(6, static fn (int $i): string => 'Os'.$i.'%3Ax'),
                'Można podać opcje najwyżej 5 różnych osi.',
            ],
        ];
        foreach ($cases as $label => [$valid, $invalid, $title]) {
            yield InputCase::query(self::PRODUCTS, null, InputContractFixtures::NONE, 'option', $label, $valid, $invalid, [['option', $title]]);
        }
    }

    /**
     * `$count` repeated `option` keys, the value of the i-th (from 1) given by `$entry`.
     *
     * @param \Closure(int): string $entry
     */
    private static function optionEntries(int $count, \Closure $entry): string
    {
        return implode('&', array_map(static fn (int $i): string => 'option='.$entry($i), range(1, $count)));
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
        yield InputCase::query(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'status', 'unknown status', 'status=published', 'status=archived', [['status', 'Status produktu musi być jednym z: draft, published.']]);
        yield InputCase::query(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'after', 'after=abc', 'after='.self::productCursor(), 'after=abc', [['after', 'Kursor stronicowania jest nieprawidłowy.']]);
        yield InputCase::query(self::BACKOFFICE_PRODUCTS, $staff, 'product', 'q', 'q=ab%00', 'q=namiot', 'q=ab%00', [['q', 'Szukana fraza zawiera niedozwolone znaki.']]);

        yield ValidatorRows::ofAnotherKind(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'sort', 'sort=-name', 'Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.');
        yield ValidatorRows::ofAnotherKind(self::BACKOFFICE_PRODUCTS, $staff, InputContractFixtures::NONE, 'images', 'images=none', 'Filtr zdjęć może mieć tylko wartość none.');
    }
}
