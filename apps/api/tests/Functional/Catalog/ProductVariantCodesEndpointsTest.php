<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Event\ProductChanged;
use App\Tests\Support\InputConformance\InputValues;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * The SKU and the EAN of a variant in POST and PUT /api/v1/admin/products and in the reads of the panel and of the store
 * (docs/features/product-data/spec.md, "Pola i reguły", "Naruszenia", "Unikalność SKU przy równoległym zapisie", "API",
 * "Kody należą do wariantu"): saved trimmed with the SKU in capitals, refused with a violation at the row when T1, the
 * format, the repetition or another product breaks them, kept by the variant through every change of the axes and the
 * values, and read from a row stored before the fields existed.
 */
final class ProductVariantCodesEndpointsTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    private const string PRODUCTS = '/api/v1/admin/products';
    private const string TAKEN = 'Kod SKU jest już używany przez inny produkt.';
    private const string SKU_FORMAT = 'Kod SKU może mieć od 1 do 40 znaków: litery A–Z, cyfry oraz znaki - _ . / i musi zaczynać się literą albo cyfrą.';
    private const string EAN_FORMAT = 'Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną.';

    /**
     * @return list<array{variantId: null, values: list<string>, sku: string, ean: string}>
     */
    private static function rows(string $firstSku = ' but-m ', string $secondSku = "\u{00A0}BUT-L\u{200B}"): array
    {
        return [
            ['variantId' => null, 'values' => ['M'], 'sku' => $firstSku, 'ean' => "\u{3000}5901234123457 "],
            ['variantId' => null, 'values' => ['L'], 'sku' => $secondSku, 'ean' => '2000000000008'],
        ];
    }

    public function testTheCodesAreSavedTrimmedWithTheSkuInCapitalsAndReadByThePanelAndByTheStore(): void
    {
        $view = $this->createProduct(['variants' => self::rows()]);

        self::assertSame([['BUT-M', '5901234123457'], ['BUT-L', '2000000000008']], $this->codes($view));
        $this->assertKeysMatchSchema($view['variants'][0], 'ProductEditVariantResponse');
        self::assertSame($view, $this->api('GET', self::PRODUCTS.'/'.self::string($view['id'])));
        self::assertSame(
            [['BUT-M', '5901234123457'], ['BUT-L', '2000000000008']],
            array_map(static fn (array $row): array => array_values($row), $this->connection->fetchAllAssociative('SELECT sku, ean FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [$view['id']])),
        );

        $this->publish($view);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertResponseStatusCodeSame(200);
        // The shop gets the code of the seller and the global trade item number: empty for a number of restricted circulation.
        self::assertSame([['BUT-M', '5901234123457', '5901234123457'], ['BUT-L', '2000000000008', '']], array_map(
            static fn (array $variant): array => [$variant['sku'], $variant['ean'], $variant['gtin']],
            self::list($page['variants']),
        ));
        $this->assertKeysMatchSchemaOfTheShop($page['variants'][0]);
    }

    public function testAVariantWithoutCodesHasEmptyTextsEverywhere(): void
    {
        $view = $this->createProduct(['variants' => [['variantId' => null, 'values' => ['M'], 'sku' => "\u{00A0}", 'ean' => ''], ['variantId' => null, 'values' => ['L'], 'sku' => '', 'ean' => "\u{200B}"]]]);

        self::assertSame([['', ''], ['', '']], $this->codes($view));
        self::assertSame([[null, null], [null, null]], array_map(static fn (array $row): array => array_values($row), $this->connection->fetchAllAssociative('SELECT sku, ean FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [$view['id']])));
        $this->publish($view);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertSame([['', '', ''], ['', '', '']], array_map(static fn (array $variant): array => [$variant['sku'], $variant['ean'], $variant['gtin']], self::list($page['variants'])));
    }

    public function testAnEditReplacesAddsAndRemovesTheCodes(): void
    {
        $created = $this->createProduct(['variants' => self::rows()]);
        $rows = $created['variants'];

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, ['variants' => [
            [...$rows[0], 'sku' => 'nowy-1', 'ean' => ''],
            [...$rows[1], 'sku' => '', 'ean' => '59012344'],
        ]]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['NOWY-1', ''], ['', '59012344']], $this->codes($edited));
        self::assertSame($edited, $this->api('GET', self::PRODUCTS.'/'.self::string($created['id'])));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function changesOfOneCodeAlone(): iterable
    {
        yield 'the SKU' => [['sku' => 'NOWY-SKU']];
        yield 'the EAN' => [['ean' => '59012344']];
    }

    /**
     * @param array<string, string> $change
     */
    #[DataProvider('changesOfOneCodeAlone')]
    public function testAChangeOfOneCodeAloneChangesNoOtherCodeRaisesTheVersionAndPublishesProductChanged(array $change): void
    {
        $created = $this->publish($this->createProduct(['variants' => self::rows()]));
        $path = self::PRODUCTS.'/'.self::string($created['id']);
        $rows = $created['variants'];

        $edited = $this->api('PUT', $path, $this->editBody($created, ['variants' => [$rows[0], [...$rows[1], ...$change]]]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($created['variants'][0], $edited['variants'][0], 'the codes of the other variant are untouched');
        self::assertSame([...$created['variants'][1], ...$change], $edited['variants'][1]);
        self::assertSame(3, $edited['version']);
        self::assertSame(1, $this->sentInTheLastRequest(ProductChanged::class));

        // The old version is stale now: the same request is a conflict of the version, never a violation at the SKU.
        $this->api('PUT', $path, $this->editBody($created, ['variants' => [$rows[0], [...$rows[1], ...$change]]]));
        $this->assertProblem(409, 'product-modified');
        self::assertSame(3, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]));
    }

    /**
     * Each change of the axes and the values of a draft that a variant survives (criterion 8a): the variants of the same
     * id keep their codes, the codes are written with every row the repository replaces. A dataset: the axes and the values
     * of the two rows of the product, then the new axes and the new rows (the position of the row it was, its new values).
     *
     * @return iterable<string, array{list<string>, list<list<string>>, list<string>, list<array{int, list<string>}>}>
     */
    public static function changesOfAxesAndValues(): iterable
    {
        yield 'an axis added' => [['Rozmiar'], [['M'], ['L']], ['Rozmiar', 'Kolor'], [[0, ['M', 'czarne']], [1, ['L', 'czarne']]]];
        yield 'an axis removed' => [['Rozmiar', 'Kolor'], [['M', 'czarne'], ['L', 'czarne']], ['Rozmiar'], [[0, ['M']], [1, ['L']]]];
        yield 'the axes reordered' => [['Rozmiar', 'Kolor'], [['M', 'czarne'], ['L', 'czarne']], ['Kolor', 'Rozmiar'], [[0, ['czarne', 'M']], [1, ['czarne', 'L']]]];
        yield 'an axis renamed' => [['Rozmiar'], [['M'], ['L']], ['Wielkość'], [[0, ['M']], [1, ['L']]]];
        yield 'a value changed' => [['Rozmiar'], [['M'], ['L']], ['Rozmiar'], [[0, ['XXL']], [1, ['L']]]];
        yield 'the rows reordered' => [['Rozmiar'], [['M'], ['L']], ['Rozmiar'], [[1, ['L']], [0, ['M']]]];
        yield 'the last axis removed' => [['Rozmiar'], [['M'], ['L']], [], [[0, []]]];
        yield 'the last axis removed and the other variant kept as the default one' => [['Rozmiar'], [['M'], ['L']], [], [[1, []]]];
    }

    /**
     * @param list<string>                       $axes
     * @param list<list<string>>                 $values
     * @param list<string>                       $newAxes
     * @param list<array{int, list<string>}>     $newRows
     */
    #[DataProvider('changesOfAxesAndValues')]
    public function testTheVariantOfTheSameIdKeepsItsCodesWhenTheAxesAndTheValuesOfADraftChange(array $axes, array $values, array $newAxes, array $newRows): void
    {
        $created = $this->createProduct(['variantAxes' => $axes, 'variants' => [
            ['variantId' => null, 'values' => $values[0], 'sku' => 'but-m', 'ean' => '5901234123457'],
            ['variantId' => null, 'values' => $values[1], 'sku' => 'but-l', 'ean' => '59012344'],
        ]]);
        $rows = self::list($created['variants']);
        $before = [];
        foreach ($rows as $row) {
            $before[self::string($row['variantId'])] = [$row['sku'], $row['ean']];
        }
        $kept = array_map(static fn (array $row): string => self::string($rows[$row[0]]['variantId']), $newRows);

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, [
            'variantAxes' => $newAxes,
            'variants' => array_map(static fn (array $row): array => [...$rows[$row[0]], 'values' => $row[1]], $newRows),
        ]));

        self::assertResponseStatusCodeSame(200);
        $after = [];
        foreach (self::list($edited['variants']) as $row) {
            $after[self::string($row['variantId'])] = [$row['sku'], $row['ean']];
        }
        self::assertSame($kept, array_keys($after), 'the same variants, in the order of the rows');
        foreach ($after as $id => $codes) {
            self::assertSame($before[$id], $codes, 'the codes of the variant '.$id);
        }
        self::assertSame($edited['variants'], $this->api('GET', self::PRODUCTS.'/'.self::string($created['id']))['variants']);
    }

    public function testAfterThePublicationTheCodesStayWhenAKeptVariantChangesOnlyTheCaseOrTheEdgesOfItsValueOrTheNameOfItsAxis(): void
    {
        $created = $this->publish($this->createProduct(['variantAxes' => ['Rozmiar'], 'variants' => [
            ['variantId' => null, 'values' => ['M'], 'sku' => 'but-m', 'ean' => '5901234123457'],
            ['variantId' => null, 'values' => ['L'], 'sku' => 'but-l', 'ean' => ''],
        ]]));
        $rows = self::list($created['variants']);

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, [
            'variantAxes' => ['ROZMIAR'],
            'variants' => [[...$rows[0], 'values' => [' m ']], [...$rows[1], 'values' => ['l']]],
        ]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['BUT-M', '5901234123457'], ['BUT-L', '']], $this->codes($edited));
        self::assertSame(array_column($rows, 'variantId'), array_column(self::list($edited['variants']), 'variantId'));
    }

    public function testTheCodesDoNotLetAPublishedVariantChangeItsValue(): void
    {
        $created = $this->publish($this->createProduct(['variants' => self::rows()]));
        $rows = self::list($created['variants']);

        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, ['variants' => [[...$rows[0], 'values' => ['XL']], $rows[1]]]));

        $this->assertProblem(409, 'variant-locked');
    }

    public function testTheCodesSurviveAnEditThatReplacesTheRowsOfAProductWithoutAnyChangeOfTheValues(): void
    {
        $created = $this->createProduct(['variants' => self::rows()]);

        // Only the order of the variants changes: the repository replaces the rows.
        $rows = self::list($created['variants']);
        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, ['variants' => array_reverse($rows)]));

        self::assertSame([['BUT-L', '2000000000008'], ['BUT-M', '5901234123457']], $this->codes($edited));
    }

    public function testTwoVariantsOfOneProductSwapTheirSkusInOneSave(): void
    {
        $created = $this->createProduct(['variants' => self::rows('BUT-M', 'BUT-L')]);
        $rows = self::list($created['variants']);

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, ['variants' => [
            [...$rows[0], 'sku' => 'BUT-L'],
            [...$rows[1], 'sku' => 'but-m'],
        ]]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['BUT-L', 'BUT-M'], array_column(self::list($edited['variants']), 'sku'));
    }

    /**
     * @return iterable<string, array{string, mixed, string, string}>
     */
    public static function missingAndWrongTypes(): iterable
    {
        yield 'SKU missing' => ['sku', null, 'variants[1].sku', 'Kod SKU musi być tekstem.'];
        yield 'SKU null' => ['sku', 'null', 'variants[1].sku', 'Kod SKU musi być tekstem.'];
        yield 'SKU a number' => ['sku', 7, 'variants[1].sku', 'Kod SKU musi być tekstem.'];
        yield 'SKU a list' => ['sku', ['A1'], 'variants[1].sku', 'Kod SKU musi być tekstem.'];
        yield 'SKU true' => ['sku', true, 'variants[1].sku', 'Kod SKU musi być tekstem.'];
        yield 'EAN missing' => ['ean', null, 'variants[1].ean', 'Kod EAN musi być tekstem.'];
        yield 'EAN null' => ['ean', 'null', 'variants[1].ean', 'Kod EAN musi być tekstem.'];
        yield 'EAN a JSON number' => ['ean', 5901234123457, 'variants[1].ean', 'Kod EAN musi być tekstem.'];
        yield 'EAN a JSON number of 8 digits' => ['ean', 59012344, 'variants[1].ean', 'Kod EAN musi być tekstem.'];
        yield 'EAN an object' => ['ean', ['a' => 'b'], 'variants[1].ean', 'Kod EAN musi być tekstem.'];
    }

    #[DataProvider('missingAndWrongTypes')]
    public function testAMissingKeyAndAWrongTypeAreViolationsAtTheirRowAndNothingIsSaved(string $field, mixed $value, string $path, string $title): void
    {
        $rows = $this->bodyRows();
        if (null === $value) {
            unset($rows[1][$field]);
        } else {
            $rows[1][$field] = 'null' === $value ? null : $value;
        }
        $this->api('POST', self::PRODUCTS, $this->body(['variants' => $rows]));

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));

        $created = $this->createProduct();
        $edit = $this->editBody($created);
        $edit['variants'] = $this->bodyRows($created['variants']);
        if (null === $value) {
            unset($edit['variants'][1][$field]);
        } else {
            $edit['variants'][1][$field] = 'null' === $value ? null : $value;
        }
        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $edit);

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function badFormats(): iterable
    {
        yield 'a SKU with a space inside' => ['sku', 'AB 1', 'variants[1].sku', self::SKU_FORMAT];
        yield 'a SKU starting with a dash' => ['sku', '-AB1', 'variants[1].sku', self::SKU_FORMAT];
        yield 'a SKU with a Polish letter' => ['sku', 'ŻÓŁW-1', 'variants[1].sku', self::SKU_FORMAT];
        yield 'a SKU with a plus' => ['sku', 'AB+1', 'variants[1].sku', self::SKU_FORMAT];
        yield 'a SKU of 41 characters' => ['sku', str_repeat('A', 41), 'variants[1].sku', self::SKU_FORMAT];
        yield 'a SKU with a zero width space inside' => ['sku', "AB\u{200B}1", 'variants[1].sku', self::SKU_FORMAT];
        yield 'an EAN of 9 digits' => ['ean', '123456789', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN of 15 digits' => ['ean', '123456789012345', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN with letters' => ['ean', '590123412345A', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN of 8 digits with a wrong check digit' => ['ean', '59012345', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN of 12 digits with a wrong check digit' => ['ean', '012345678906', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN of 13 digits with a wrong check digit' => ['ean', '5901234123458', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN of 14 digits with a wrong check digit' => ['ean', '15901234123455', 'variants[1].ean', self::EAN_FORMAT];
        yield 'an EAN with a space inside' => ['ean', '5901234 123457', 'variants[1].ean', self::EAN_FORMAT];
    }

    #[DataProvider('badFormats')]
    public function testACodeOfABadFormatIsAViolationAtItsRowAndNothingIsSaved(string $field, string $value, string $path, string $title): void
    {
        $rows = $this->bodyRows();
        $rows[1][$field] = $value;
        $this->api('POST', self::PRODUCTS, $this->body(['variants' => $rows]));

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    /**
     * T1 is checked on the value as it came, before the trim of the ends: a character a trim would drop is refused, not
     * cut off (PRODUCT-DATA, criterion 9a).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenAtTheEdges(): iterable
    {
        foreach (['sku' => 'BUT-1', 'ean' => '5901234123457'] as $field => $code) {
            yield "a tab at the end of the $field" => [$field, $code."\t"];
            yield "a tab at the start of the $field" => [$field, "\t".$code];
            yield "U+2028 at the end of the $field" => [$field, $code."\u{2028}"];
            yield "U+2029 at the start of the $field" => [$field, "\u{2029}".$code];
            yield "a line feed at the end of the $field" => [$field, $code."\n"];
            yield "a carriage return at the end of the $field" => [$field, $code."\r"];
            yield "U+0085 at the start of the $field" => [$field, "\u{0085}".$code];
            yield "NUL inside the $field" => [$field, substr($code, 0, 2)."\x00".substr($code, 2)];
        }
    }

    #[DataProvider('forbiddenAtTheEdges')]
    public function testACharacterOfT1AtTheEdgeIsAFormatViolationAtTheFieldAndNoCodeIsSaved(string $field, string $value): void
    {
        $title = 'sku' === $field ? self::SKU_FORMAT : self::EAN_FORMAT;
        $rows = $this->bodyRows();
        $rows[1][$field] = $value;
        $this->api('POST', self::PRODUCTS, $this->body(['variants' => $rows]));

        self::assertSame([['propertyPath' => 'variants[1].'.$field, 'title' => $title]], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));

        $created = $this->createProduct(['variants' => self::rows()]);
        $edit = $this->editBody($created, ['variants' => $this->bodyRows($created['variants'])]);
        $edit['variants'][1][$field] = $value;
        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $edit);

        self::assertSame([['propertyPath' => 'variants[1].'.$field, 'title' => $title]], $this->violations());
        self::assertSame([1, 'BUT-L', '2000000000008'], [
            $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]),
            $this->connection->fetchOne('SELECT sku FROM catalog_product_variant WHERE product_id = ? AND position = 1', [$created['id']]),
            $this->connection->fetchOne('SELECT ean FROM catalog_product_variant WHERE product_id = ? AND position = 1', [$created['id']]),
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyCharacterOfT1(): iterable
    {
        yield from InputValues::forbiddenTextCharacters();
    }

    #[DataProvider('everyCharacterOfT1')]
    public function testEveryForbiddenCharacterAtTheEndOfACodeIsRefusedAndNeverAServerError(string $character): void
    {
        foreach (['sku' => 'BUT-1', 'ean' => '5901234123457'] as $field => $code) {
            $rows = $this->bodyRows();
            $rows[0][$field] = $code.$character;
            $this->api('POST', self::PRODUCTS, $this->body(['variants' => $rows]));

            self::assertSame([['propertyPath' => 'variants[0].'.$field, 'title' => 'sku' === $field ? self::SKU_FORMAT : self::EAN_FORMAT]], $this->violations());
        }
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTexts(): iterable
    {
        yield from InputValues::blankUnicodeTexts();
        yield 'zero width characters' => ["\u{200B}\u{2060}\u{FEFF}\u{00AD}"];
    }

    #[DataProvider('blankTexts')]
    public function testACodeOfOnlyWhiteSpaceIsNoCode(string $blank): void
    {
        $view = $this->createProduct(['variants' => [['variantId' => null, 'values' => ['M'], 'sku' => $blank, 'ean' => $blank], ['variantId' => null, 'values' => ['L'], 'sku' => $blank, 'ean' => $blank]]]);

        self::assertSame([['', ''], ['', '']], $this->codes($view));
    }

    public function testTheSameSkuInTwoRowsIsAViolationAtTheLaterRowWhateverItsCase(): void
    {
        $this->api('POST', self::PRODUCTS, $this->body(['variants' => [
            ['variantId' => null, 'values' => ['M'], 'sku' => 'BUT-1', 'ean' => ''],
            ['variantId' => null, 'values' => ['L'], 'sku' => 'BUT-2', 'ean' => ''],
            ['variantId' => null, 'values' => ['XL'], 'sku' => ' but-1 ', 'ean' => ''],
        ]]));

        self::assertSame([['propertyPath' => 'variants[2].sku', 'title' => 'Ten kod SKU powtarza się w produkcie.']], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testTheSameEanInTwoRowsIsAccepted(): void
    {
        $view = $this->createProduct(['variants' => [
            ['variantId' => null, 'values' => ['M'], 'sku' => '', 'ean' => '5901234123457'],
            ['variantId' => null, 'values' => ['L'], 'sku' => '', 'ean' => '5901234123457'],
        ]]);

        self::assertSame([['', '5901234123457'], ['', '5901234123457']], $this->codes($view));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function skusOfAnotherProduct(): iterable
    {
        yield 'the same code' => ['BUT-1', 'BUT-1'];
        yield 'the same code in small letters' => ['BUT-1', 'but-1'];
        yield 'the same code with edges' => ['but-1', "\u{00A0}BUT-1\u{200B}"];
    }

    #[DataProvider('skusOfAnotherProduct')]
    public function testASkuOfAVariantOfAnotherProductIsAViolationAtItsRowAndNothingIsSaved(string $existing, string $given): void
    {
        $this->createProduct(['slug' => 'pierwszy', 'variants' => [['variantId' => null, 'values' => ['M'], 'sku' => $existing, 'ean' => '']], 'variantAxes' => ['Rozmiar']]);
        $second = $this->createProduct(['slug' => 'drugi', 'variants' => [['variantId' => null, 'values' => ['M'], 'sku' => 'DRUGI-1', 'ean' => '']]]);

        $this->api('POST', self::PRODUCTS, $this->body(['slug' => 'trzeci', 'variants' => [
            ['variantId' => null, 'values' => ['M'], 'sku' => 'WOLNY-1', 'ean' => ''],
            ['variantId' => null, 'values' => ['L'], 'sku' => $given, 'ean' => ''],
        ]]));

        self::assertSame([['propertyPath' => 'variants[1].sku', 'title' => self::TAKEN]], $this->violations());
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));

        $this->api('PUT', self::PRODUCTS.'/'.self::string($second['id']), $this->editBody($second, ['variants' => [[...$second['variants'][0], 'sku' => $given]]]));

        self::assertSame([['propertyPath' => 'variants[0].sku', 'title' => self::TAKEN]], $this->violations());
        self::assertSame(['DRUGI-1', 1], [
            $this->connection->fetchOne('SELECT sku FROM catalog_product_variant WHERE product_id = ?', [$second['id']]),
            $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$second['id']]),
        ]);
    }

    public function testASkuOfADraftIsTakenAsWellAndASkuOfARemovedVariantIsFreeAtOnce(): void
    {
        $first = $this->createProduct(['slug' => 'pierwszy', 'variants' => [
            ['variantId' => null, 'values' => ['M'], 'sku' => 'BUT-1', 'ean' => ''],
            ['variantId' => null, 'values' => ['L'], 'sku' => '', 'ean' => ''],
        ]]);
        $body = $this->body(['slug' => 'drugi', 'variants' => [['variantId' => null, 'values' => ['M'], 'sku' => 'but-1', 'ean' => '']]]);
        $this->api('POST', self::PRODUCTS, $body);
        self::assertSame([['propertyPath' => 'variants[0].sku', 'title' => self::TAKEN]], $this->violations());

        $this->api('PUT', self::PRODUCTS.'/'.self::string($first['id']), $this->editBody($first, ['variants' => [$first['variants'][1]]]));
        self::assertResponseStatusCodeSame(200);

        $this->api('POST', self::PRODUCTS, $body);

        self::assertResponseStatusCodeSame(201);
    }

    public function testAProductMayKeepItsOwnSkusWhenItIsSavedAgain(): void
    {
        $created = $this->createProduct(['variants' => self::rows('BUT-M', 'BUT-L')]);

        $saved = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->codes($created), $this->codes($saved));
    }

    public function testASkuTakenBetweenTheCheckAndTheWriteIsA409SkuTakenWithoutViolationsAndNothingIsSaved(): void
    {
        $category = $this->category->id()->toString();
        // A product that commits its variant with the SKU after the handler checked, before the variants are written.
        $this->entityManager->getEventManager()->addEventListener(Events::onFlush, new class($this->connection, $category) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly string $category)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $this->connection->executeStatement(
                    "INSERT INTO catalog_product (id, slug, name, short_description, primary_category_id, tax_class, images, variant_axes, status, updated_at, version)
                     VALUES ('99999999-9999-4999-8999-999999999999', 'konkurent', 'Konkurent', '', ?, 'standard', '[]'::jsonb, '[]'::jsonb, 'draft', now(), 1)",
                    [$this->category],
                );
                $this->connection->executeStatement(
                    "INSERT INTO catalog_product_variant (id, product_id, position, option_values, sku) VALUES ('99999999-9999-4999-8999-999999999998', '99999999-9999-4999-8999-999999999999', 0, '[]'::jsonb, 'RACE-1')",
                );
            }
        });

        $this->api('POST', self::PRODUCTS, $this->body(['variantAxes' => [], 'variants' => [['variantId' => null, 'values' => [], 'sku' => 'race-1', 'ean' => '']]]));

        $problem = $this->assertProblem(409, 'sku-taken', self::TAKEN);
        self::assertArrayNotHasKey('violations', $problem);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM catalog_product'), 'nothing of the write or of the competitor is left');
    }

    public function testACodeStoredBeforeTheFieldsExistedIsReadAndSaved(): void
    {
        $created = $this->createProduct(['variants' => self::rows()]);
        $id = self::string($created['id']);
        // The state ADD COLUMN gives the variants of before the migration.
        $this->connection->executeStatement('UPDATE catalog_product_variant SET sku = NULL, ean = NULL WHERE product_id = ?', [$id]);

        $view = $this->api('GET', self::PRODUCTS.'/'.$id);

        self::assertSame([['', ''], ['', '']], $this->codes($view));
        $saved = $this->api('PUT', self::PRODUCTS.'/'.$id, $this->editBody($view, ['variants' => [[...$view['variants'][0], 'sku' => 'nowy']]]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['NOWY', ''], ['', '']], $this->codes($saved));
    }

    public function testACodeStoredOutsideTheRuleIsReadAsItIsAndItsEditHasToFixIt(): void
    {
        $created = $this->publish($this->createProduct(['variants' => self::rows()]));
        $id = self::string($created['id']);
        $this->connection->executeStatement("UPDATE catalog_product_variant SET sku = 'low case', ean = '123' WHERE product_id = ? AND position = 0", [$id]);

        $view = $this->api('GET', self::PRODUCTS.'/'.$id);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['low case', '123'], [$view['variants'][0]['sku'], $view['variants'][0]['ean']]);
        self::assertSame(['low case', '123', ''], [$page['variants'][0]['sku'], $page['variants'][0]['ean'], $page['variants'][0]['gtin']]);

        $this->api('PUT', self::PRODUCTS.'/'.$id, $this->editBody($view));
        self::assertSame([
            ['propertyPath' => 'variants[0].sku', 'title' => self::SKU_FORMAT],
            ['propertyPath' => 'variants[0].ean', 'title' => self::EAN_FORMAT],
        ], $this->violations());
    }

    /**
     * @param array<mixed> $view
     *
     * @return list<array{string, string}> the SKU and the EAN of every variant
     */
    private function codes(array $view): array
    {
        return array_map(static fn (array $row): array => [self::string($row['sku']), self::string($row['ean'])], self::list($view['variants']));
    }

    /**
     * @param list<array<mixed>>|null $rows the rows of a view, or the rows of a body without codes
     *
     * @return list<array<string, mixed>>
     */
    private function bodyRows(?array $rows = null): array
    {
        return array_map(static fn (array $row): array => [
            'variantId' => $row['variantId'] ?? null,
            'values' => $row['values'],
            'sku' => '',
            'ean' => '',
        ], $rows ?? [['variantId' => null, 'values' => ['M']], ['variantId' => null, 'values' => ['L']]]);
    }

    /**
     * @param array<mixed> $variant
     */
    private function assertKeysMatchSchemaOfTheShop(array $variant): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas']['ProductDetailsVariant'] ?? null;
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($variant));
        self::assertEqualsCanonicalizing($schema['required'], array_keys($variant));
    }

    /**
     * The transports are reset when a request ends, so the outbox holds what the last request sent.
     *
     * @param class-string $event
     */
    private function sentInTheLastRequest(string $event): int
    {
        $outbox = self::getContainer()->get('messenger.transport.outbox');
        self::assertInstanceOf(InMemoryTransport::class, $outbox);

        return \count(array_filter($outbox->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $event));
    }
}
