<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the contract of texts of the operations of Catalog (TextRow): the name of a category, the texts of a product
 * in its creation and its change, and the phrases of the lists of products of the store and of the panel.
 */
final class CatalogTextRows
{
    private const string CATEGORIES = '/api/v1/admin/categories';
    private const string PRODUCTS = '/api/v1/admin/products';
    private const string PRODUCT = 'SELECT %s FROM catalog_product WHERE id = :id';

    /**
     * @return iterable<TextRow>
     */
    public static function all(): iterable
    {
        yield from self::categoryName();
        yield from self::productTexts();
        yield from self::phrases();
    }

    /**
     * @return iterable<TextRow>
     */
    private static function categoryName(): iterable
    {
        $operations = [
            'POST '.self::CATEGORIES => [InputContractFixtures::NONE, ['name' => 'Akcesoria', 'slug' => 'akcesoria', 'parentId' => null]],
            'PUT '.self::CATEGORIES.'/{id}' => ['category', ['name' => 'Akcesoria', 'slug' => 'akcesoria', 'version' => 1]],
        ];
        foreach ($operations as $operation => [$fixture, $body]) {
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'name', 'Akcesoria', $body,
                empty: 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.',
                forbidden: 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.',
                wrongType: 'Nazwa kategorii musi być tekstem.',
                stored: TextRow::column('SELECT name FROM catalog_category WHERE id = :id', ['id' => '{response.id}']));
        }
    }

    /**
     * @return iterable<TextRow>
     */
    private static function productTexts(): iterable
    {
        $body = [
            'name' => 'Buty biegowe',
            'slug' => 'buty-biegowe',
            'shortDescription' => 'Lekkie buty na szlak.',
            'brand' => 'Hallen',
            'description' => "Lekkie buty na szlak.\n\nDrugi akapit.",
            'parameters' => [['name' => 'Waga', 'value' => '2,4 kg']],
            'mainCategoryId' => '{categoryId}',
            'additionalCategoryIds' => [],
            'taxClass' => 'standard',
            'images' => [['key' => '{imageKey}', 'alt' => 'Buty z boku']],
            'variantAxes' => ['Rozmiar'],
            'variants' => [['variantId' => null, 'values' => ['Czerwony']], ['variantId' => null, 'values' => ['L']]],
        ];
        $operations = [
            'POST '.self::PRODUCTS => ['productWrite', $body],
            'PUT '.self::PRODUCTS.'/{id}' => ['productEdit', [
                'version' => 1,
                ...$body,
                'variants' => [['variantId' => '{variantM}', 'values' => ['Czerwony']], ['variantId' => '{variantL}', 'values' => ['L']]],
            ]],
        ];
        $id = ['id' => '{response.id}'];

        foreach ($operations as $operation => [$fixture, $request]) {
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'name', 'Buty biegowe', $request,
                empty: 'Podaj nazwę produktu.',
                forbidden: 'Nazwa produktu zawiera niedozwolone znaki.',
                wrongType: 'Podaj nazwę produktu.',
                stored: TextRow::column(\sprintf(self::PRODUCT, 'name'), $id));
            yield TextRow::optional($operation, InputCase::STAFF, $fixture, 'shortDescription', 'Lekkie buty na szlak.', $request,
                noValue: '',
                forbidden: 'Krótki opis zawiera niedozwolone znaki.',
                wrongType: 'Krótki opis musi być tekstem.',
                stored: TextRow::column(\sprintf(self::PRODUCT, 'short_description'), $id),
                allowed: "\n");
            yield TextRow::optional($operation, InputCase::STAFF, $fixture, 'brand', 'Hallen', $request,
                noValue: '',
                forbidden: 'Marka zawiera niedozwolone znaki.',
                wrongType: 'Marka musi być tekstem.',
                stored: TextRow::column(\sprintf(self::PRODUCT, 'brand'), $id));
            yield TextRow::optional($operation, InputCase::STAFF, $fixture, 'description', 'Pierwszy akapit opisu.', $request,
                noValue: '',
                forbidden: 'Opis zawiera niedozwolone znaki.',
                wrongType: 'Opis musi być tekstem.',
                stored: TextRow::column(\sprintf(self::PRODUCT, 'description'), $id),
                allowed: "\n");
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'parameters[0].name', 'Waga', $request,
                empty: 'Nazwa parametru musi mieć od 1 do 60 znaków.',
                forbidden: 'Nazwa parametru zawiera niedozwolone znaki.',
                wrongType: 'Nazwa parametru musi mieć od 1 do 60 znaków.',
                stored: TextRow::column(\sprintf(self::PRODUCT, "parameters->0->>'name'"), $id));
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'parameters[0].value', '2,4 kg', $request,
                empty: 'Wartość parametru musi mieć od 1 do 200 znaków.',
                forbidden: 'Wartość parametru zawiera niedozwolone znaki.',
                wrongType: 'Wartość parametru musi mieć od 1 do 200 znaków.',
                stored: TextRow::column(\sprintf(self::PRODUCT, "parameters->0->>'value'"), $id));
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'images[0].alt', 'Buty z boku', $request,
                empty: 'Podaj tekst alternatywny zdjęcia.',
                forbidden: 'Tekst alternatywny zawiera niedozwolone znaki.',
                wrongType: 'Podaj tekst alternatywny zdjęcia.',
                stored: TextRow::column(\sprintf(self::PRODUCT, "images->0->>'alt'"), $id));
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'variantAxes[0]', 'Rozmiar', $request,
                empty: 'Nazwa osi musi mieć od 1 do 30 znaków.',
                forbidden: 'Nazwa osi zawiera niedozwolone znaki.',
                wrongType: 'Nazwa osi musi mieć od 1 do 30 znaków.',
                stored: TextRow::column(\sprintf(self::PRODUCT, 'variant_axes->>0'), $id));
            yield TextRow::required($operation, InputCase::STAFF, $fixture, 'variants[0].values[0]', 'Czerwony', $request,
                empty: 'Wartość musi mieć od 1 do 40 znaków.',
                forbidden: 'Wartość wariantu zawiera niedozwolone znaki.',
                wrongType: 'Wartość musi mieć od 1 do 40 znaków.',
                stored: TextRow::column('SELECT option_values->>0 FROM catalog_product_variant WHERE product_id = :id AND position = 0', $id));
        }
    }

    /**
     * @return iterable<TextRow>
     */
    private static function phrases(): iterable
    {
        $tooShort = ValidatorRows::tooShort(2);
        $forbidden = 'Szukana fraza zawiera niedozwolone znaki.';

        yield TextRow::phrase('GET /api/v1/products', null, 'indexedProduct', 'namiot', $tooShort, $forbidden,
            phrase: '{productName}', record: '{productId}', items: 'items', id: 'id', count: 'total');
        yield TextRow::phrase('GET '.self::PRODUCTS, InputCase::STAFF, 'indexedProduct', 'namiot', $tooShort, $forbidden,
            phrase: '{productName}', record: '{productId}', items: 'items', id: 'id', count: 'total');
    }
}
