<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Texts of products and categories stored before the rule of texts that the rule refuses (spec api-input-contract,
 * E7, "Odczyt zapisanych wierszy"): the reads of the panel and of the store give them as they are stored, and only a
 * write, which sends every field, has to fix them.
 */
final class StoredTextsOutsideTheRuleTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function productTexts(): iterable
    {
        $name = 'Nazwa produktu zawiera niedozwolone znaki.';
        yield 'U+200B as the name' => ['name', "\u{200B}", 'name', 'Podaj nazwę produktu.'];
        yield 'a tab in the name' => ['name', "Buty\tbiegowe", 'name', $name];
        yield 'U+009F in the name' => ['name', "Buty\u{9F}biegowe", 'name', $name];
        yield 'a tab in the axis' => ['axis', "Roz\tmiar", 'variantAxes[0]', 'Nazwa osi zawiera niedozwolone znaki.'];
        yield 'U+009F in the axis' => ['axis', "Roz\u{9F}miar", 'variantAxes[0]', 'Nazwa osi zawiera niedozwolone znaki.'];
        yield 'a tab in a value' => ['value', "M\tL", 'variants[0].values[0]', 'Wartość wariantu zawiera niedozwolone znaki.'];
        yield 'U+009F in a value' => ['value', "M\u{9F}", 'variants[0].values[0]', 'Wartość wariantu zawiera niedozwolone znaki.'];
        yield 'U+200B as a value' => ['value', "\u{200B}", 'variants[0].values[0]', 'Wartość musi mieć od 1 do 40 znaków.'];
        yield 'a tab in the alt' => ['alt', "Buty\tz boku", 'images[0].alt', 'Tekst alternatywny zawiera niedozwolone znaki.'];
        yield 'U+009F in the alt' => ['alt', "Buty z boku\u{9F}", 'images[0].alt', 'Tekst alternatywny zawiera niedozwolone znaki.'];
    }

    #[DataProvider('productTexts')]
    public function testAProductStoredWithATextOutsideTheRuleIsReadUnchangedAndItsEditHasToFixTheText(string $field, string $text, string $path, string $title): void
    {
        $created = $this->publish($this->createProduct());
        $id = self::string($created['id']);
        $this->store($id, $field, $text);

        $expected = [
            'name' => 'name' === $field ? $text : 'Buty biegowe',
            'axes' => ['axis' === $field ? $text : 'Rozmiar'],
            'values' => [['value' => $text][$field] ?? 'M', 'L'],
            'alt' => 'alt' === $field ? $text : 'Buty z boku',
        ];

        $list = $this->api('GET', '/api/v1/admin/products');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[$id, $expected['name']]], array_map(static fn (array $item): array => [$item['id'], $item['name']], self::list($list['items'])));

        $view = $this->api('GET', '/api/v1/admin/products/'.$id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($expected['name'], $view['name']);
        self::assertSame($expected['axes'], $view['variantAxes']);
        self::assertSame($expected['values'], self::values($view['variants']));
        self::assertSame([$expected['alt']], array_column(self::list($view['images']), 'alt'));

        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($expected['name'], $page['name']);
        self::assertSame($expected['axes'], $page['variantAxes']);
        self::assertSame($expected['values'], self::values($page['variants']));
        self::assertSame([$expected['alt']], array_column(self::list($page['images']), 'alt'));

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view));
        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame($view['version'], $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$id]));

        // A command that loads the product without changing its texts goes through, and saves them as they were.
        $this->api('POST', '/api/v1/admin/products/'.$id.'/unpublish', ['version' => $view['version']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->stored($id), $this->storedAfter($id, $field, $text));
    }

    /**
     * Reading a stored text does not trim it either: a command that saves the product again keeps its ends.
     */
    public function testAStoredTextKeepsItsEndsThroughACommandThatDoesNotChangeIt(): void
    {
        $created = $this->publish($this->createProduct());
        $id = self::string($created['id']);
        $this->store($id, 'name', "\u{A0}Buty biegowe\u{200B}");
        $this->store($id, 'value', " M\u{A0}");

        $this->api('POST', '/api/v1/admin/products/'.$id.'/unpublish', ['version' => $created['version']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame("\u{A0}Buty biegowe\u{200B}", $this->connection->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$id]));
        self::assertSame(" M\u{A0}", $this->connection->fetchOne('SELECT option_values->>0 FROM catalog_product_variant WHERE product_id = ? AND position = 0', [$id]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function categoryNames(): iterable
    {
        yield 'U+200B as the name' => ["\u{200B}"];
        yield 'a tab in the name' => ["Obu\twie"];
        yield 'U+009F in the name' => ["Obuwie\u{9F}"];
    }

    #[DataProvider('categoryNames')]
    public function testACategoryStoredWithANameOutsideTheRuleIsReadUnchangedAndItsChangeHasToFixTheName(string $name): void
    {
        $id = $this->category->id()->toString();
        $this->connection->executeStatement('UPDATE catalog_category SET name = ? WHERE id = ?', [$name, $id]);
        $this->entityManager->clear();

        $tree = $this->api('GET', '/api/v1/admin/categories');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[$id, $name]], array_map(static fn (array $node): array => [$node['id'], $node['name']], self::list($tree)));

        $store = $this->api('GET', '/api/v1/categories', token: null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([[$id, $name]], array_map(static fn (array $node): array => [$node['id'], $node['name']], self::list($store)));

        $this->api('PUT', '/api/v1/admin/categories/'.$id, ['name' => $name, 'slug' => $this->category->slug()->value(), 'version' => 1]);
        self::assertSame([['propertyPath' => 'name', 'title' => 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.']], $this->violations());
        self::assertSame(['name' => $name, 'version' => 1], $this->connection->fetchAssociative('SELECT name, version FROM catalog_category WHERE id = ?', [$id]));
    }

    /**
     * @return array{name: mixed, axis: mixed, value: mixed, alt: mixed} the stored texts of the product
     */
    private function stored(string $id): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT p.name, p.variant_axes->>0 AS axis, v.option_values->>0 AS value, p.images->0->>'alt' AS alt
             FROM catalog_product p JOIN catalog_product_variant v ON v.product_id = p.id AND v.position = 0 WHERE p.id = ?",
            [$id],
        );
        self::assertIsArray($row);

        return ['name' => $row['name'], 'axis' => $row['axis'], 'value' => $row['value'], 'alt' => $row['alt']];
    }

    /**
     * @return array{name: mixed, axis: mixed, value: mixed, alt: mixed} the texts of the product as the test stored them
     */
    private function storedAfter(string $id, string $field, string $text): array
    {
        return [
            'name' => 'name' === $field ? $text : 'Buty biegowe',
            'axis' => 'axis' === $field ? $text : 'Rozmiar',
            'value' => 'value' === $field ? $text : 'M',
            'alt' => 'alt' === $field ? $text : 'Buty z boku',
        ];
    }

    /**
     * @return list<mixed> the values of all variants, in their order
     */
    private static function values(mixed $variants): array
    {
        $values = [];
        foreach (self::list($variants) as $variant) {
            self::assertIsArray($variant['values'] ?? null);
            foreach ($variant['values'] as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Writes the text in the row of the product, past the domain, as a row stored before the rule would hold it.
     */
    private function store(string $id, string $field, string $text): void
    {
        match ($field) {
            'name' => $this->connection->executeStatement('UPDATE catalog_product SET name = ? WHERE id = ?', [$text, $id]),
            'axis' => $this->connection->executeStatement('UPDATE catalog_product SET variant_axes = jsonb_build_array(?::text) WHERE id = ?', [$text, $id]),
            'value' => $this->connection->executeStatement('UPDATE catalog_product_variant SET option_values = jsonb_build_array(?::text) WHERE product_id = ? AND position = 0', [$text, $id]),
            'alt' => $this->connection->executeStatement("UPDATE catalog_product SET images = jsonb_set(images, '{0,alt}', to_jsonb(?::text)) WHERE id = ?", [$text, $id]),
            default => throw new \LogicException($field),
        };
        $this->entityManager->clear();
    }
}
