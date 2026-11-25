<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Event\ProductChanged;
use App\Tests\Support\InputConformance\InputValues;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * The brand, the description and the parameters of a product in POST and PUT /api/v1/admin/products and in the reads of
 * the panel and of the store (docs/features/product-data/spec.md, "Pola i reguły", "Naruszenia", "API"): saved
 * trimmed by the rule of texts, refused with a violation at the field when a text breaks T1 in the value as it came,
 * raising the version like any other field, and read from a row stored before the rule or before the fields existed.
 */
final class ProductContentEndpointsTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    private const string PRODUCTS = '/api/v1/admin/products';

    /**
     * @return array<string, mixed>
     */
    private static function content(): array
    {
        return [
            'brand' => "\u{00A0}Hallen\u{200B}",
            'description' => "\n Pierwszy akapit.\n\nDrugi akapit z <b>znacznikiem</b>.\u{3000}\n",
            'parameters' => [['name' => ' Liczba osób', 'value' => '4'], ['name' => 'Waga', 'value' => "2,4 kg\u{00A0}"], ['name' => 'Wodoodporność', 'value' => '3000 mm']],
        ];
    }

    private const array TRIMMED = [
        'brand' => 'Hallen',
        'description' => "Pierwszy akapit.\n\nDrugi akapit z <b>znacznikiem</b>.",
        'parameters' => [['name' => 'Liczba osób', 'value' => '4'], ['name' => 'Waga', 'value' => '2,4 kg'], ['name' => 'Wodoodporność', 'value' => '3000 mm']],
    ];

    public function testTheContentIsSavedTrimmedAndReadByThePanelAndByTheStore(): void
    {
        $view = $this->createProduct(self::content());

        self::assertSame(self::TRIMMED['brand'], $view['brand']);
        self::assertSame(self::TRIMMED['description'], $view['description']);
        self::assertSame(self::TRIMMED['parameters'], $view['parameters']);
        $this->assertKeysMatchSchema($view, 'ProductEditViewResponse');
        self::assertSame($view, $this->api('GET', self::PRODUCTS.'/'.self::string($view['id'])));
        self::assertSame(
            ['Hallen', self::TRIMMED['description'], '[{"name": "Liczba osób", "value": "4"}, {"name": "Waga", "value": "2,4 kg"}, {"name": "Wodoodporność", "value": "3000 mm"}]'],
            array_values($this->connection->fetchAssociative('SELECT brand, description, parameters FROM catalog_product WHERE id = ?', [$view['id']]) ?: []),
        );

        $this->publish($view);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::TRIMMED['brand'], $page['brand']);
        self::assertSame(self::TRIMMED['description'], $page['description']);
        self::assertSame(self::TRIMMED['parameters'], $page['parameters']);
    }

    public function testAnEditReplacesTheContentAndEmptyValuesRemoveIt(): void
    {
        $created = $this->createProduct(self::content());

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, [
            'brand' => 'Inna marka',
            'parameters' => [['name' => 'Kolor', 'value' => 'zielony']],
        ]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Inna marka', self::TRIMMED['description'], [['name' => 'Kolor', 'value' => 'zielony']]], [$edited['brand'], $edited['description'], $edited['parameters']]);

        $cleared = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($edited, [
            'brand' => "\u{200B}",
            'description' => " \n\u{200D}",
            'parameters' => [],
        ]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['', '', []], [$cleared['brand'], $cleared['description'], $cleared['parameters']]);
        self::assertSame(['', '', '[]'], array_values($this->connection->fetchAssociative('SELECT brand, description, parameters FROM catalog_product WHERE id = ?', [$created['id']]) ?: []));
    }

    public function testTheOrderOfTheParametersIsKept(): void
    {
        $created = $this->createProduct(self::content());
        $reversed = array_reverse(self::TRIMMED['parameters']);

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, ['parameters' => $reversed]));

        self::assertSame($reversed, $edited['parameters']);
        self::assertSame(2, $edited['version']);
        self::assertSame($reversed, $this->api('GET', self::PRODUCTS.'/'.self::string($created['id']))['parameters']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function changesOfTheContentAlone(): iterable
    {
        yield 'the brand' => [['brand' => 'Hallen']];
        yield 'the description' => [['description' => 'Nowy opis']];
        yield 'the parameters' => [['parameters' => [['name' => 'Waga', 'value' => '1 kg']]]];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('changesOfTheContentAlone')]
    public function testAChangeOfTheContentAloneRaisesTheVersionAndPublishesProductChanged(array $change): void
    {
        $created = $this->createProduct();
        $path = self::PRODUCTS.'/'.self::string($created['id']);

        $edited = $this->api('PUT', $path, $this->editBody($created, $change));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $edited['version']);
        self::assertSame(1, $this->sentInTheLastRequest(ProductChanged::class));
        self::assertSame(2, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]));

        // The old version is stale now: the same request is a conflict, not a second change.
        $this->api('PUT', $path, $this->editBody($created, $change));
        $this->assertProblem(409, 'product-modified');
        self::assertSame(2, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]));
    }

    public function testAChangeOfTheContentOfAPublishedProductIsAllowedAndShownByTheStore(): void
    {
        $published = $this->publish($this->createProduct());

        $this->api('PUT', self::PRODUCTS.'/'.self::string($published['id']), $this->editBody($published, ['brand' => 'Nowa', 'parameters' => [['name' => 'Waga', 'value' => '1 kg']]]));

        self::assertResponseStatusCodeSame(200);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertSame(['Nowa', [['name' => 'Waga', 'value' => '1 kg']]], [$page['brand'], $page['parameters']]);
    }

    /**
     * @return iterable<string, array{string, mixed, string, string}>
     */
    public static function missingAndWrongTypes(): iterable
    {
        yield 'brand missing' => ['brand', null, 'brand', 'Marka musi być tekstem.'];
        yield 'brand a number' => ['brand', 7, 'brand', 'Marka musi być tekstem.'];
        yield 'brand a list' => ['brand', ['Hallen'], 'brand', 'Marka musi być tekstem.'];
        yield 'brand an object' => ['brand', ['a' => 'b'], 'brand', 'Marka musi być tekstem.'];
        yield 'description missing' => ['description', null, 'description', 'Opis musi być tekstem.'];
        yield 'description a number' => ['description', 7, 'description', 'Opis musi być tekstem.'];
        yield 'description true' => ['description', true, 'description', 'Opis musi być tekstem.'];
        yield 'parameters missing' => ['parameters', null, 'parameters', 'Parametry muszą być listą.'];
        yield 'parameters a text' => ['parameters', 'Waga', 'parameters', 'Parametry muszą być listą.'];
        yield 'parameters an object of numbered keys' => ['parameters', ['0' => ['name' => 'Waga', 'value' => '1'], '2' => ['name' => 'Kolor', 'value' => 'x']], 'parameters', 'Parametry muszą być listą.'];
        yield 'a parameter that is a text' => ['parameters', ['Waga'], 'parameters[0]', 'Parametr musi mieć nazwę i wartość.'];
        yield 'a parameter without a value' => ['parameters', [['name' => 'Waga']], 'parameters[0]', 'Parametr musi mieć nazwę i wartość.'];
        yield 'a parameter name that is a number' => ['parameters', [['name' => 7, 'value' => 'x']], 'parameters[0].name', 'Nazwa parametru musi mieć od 1 do 60 znaków.'];
        yield 'a parameter value that is a list' => ['parameters', [['name' => 'Waga', 'value' => ['1']]], 'parameters[0].value', 'Wartość parametru musi mieć od 1 do 200 znaków.'];
        yield 'a parameter value that is null' => ['parameters', [['name' => 'Waga', 'value' => null]], 'parameters[0].value', 'Wartość parametru musi mieć od 1 do 200 znaków.'];
    }

    #[DataProvider('missingAndWrongTypes')]
    public function testAMissingKeyAndAWrongTypeAreViolationsAtTheirFieldAndNothingIsSaved(string $field, mixed $value, string $path, string $title): void
    {
        $body = $this->body();
        if (null === $value) {
            unset($body[$field]);
        } else {
            $body[$field] = $value;
        }
        $this->api('POST', self::PRODUCTS, $body);

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));

        $created = $this->createProduct();
        $edit = $this->editBody($created);
        if (null === $value) {
            unset($edit[$field]);
        } else {
            $edit[$field] = $value;
        }
        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $edit);

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]));
    }

    public function testTheLimitsAndTheRepeatedNamesAreViolationsAtTheirPlaces(): void
    {
        $this->api('POST', self::PRODUCTS, $this->body([
            'brand' => str_repeat('a', 61),
            'description' => str_repeat('a', 5001),
            'parameters' => [
                ['name' => 'Waga', 'value' => '1 kg'],
                ['name' => str_repeat('a', 61), 'value' => ' '],
                ['name' => ' waga', 'value' => str_repeat('b', 201)],
                ['name' => 'Żywotność', 'value' => '1'],
                ['name' => 'ŻYWOTNOŚĆ', 'value' => '2'],
            ],
        ]));

        self::assertSame([
            ['propertyPath' => 'brand', 'title' => 'Marka może mieć najwyżej 60 znaków.'],
            ['propertyPath' => 'description', 'title' => 'Opis może mieć najwyżej 5000 znaków.'],
            ['propertyPath' => 'parameters[1].name', 'title' => 'Nazwa parametru musi mieć od 1 do 60 znaków.'],
            ['propertyPath' => 'parameters[1].value', 'title' => 'Wartość parametru musi mieć od 1 do 200 znaków.'],
            ['propertyPath' => 'parameters[2].name', 'title' => 'Parametr o tej nazwie już jest na liście.'],
            ['propertyPath' => 'parameters[2].value', 'title' => 'Wartość parametru musi mieć od 1 do 200 znaków.'],
            ['propertyPath' => 'parameters[4].name', 'title' => 'Parametr o tej nazwie już jest na liście.'],
        ], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testThirtyParametersAreSavedAndThirtyOneAreRefused(): void
    {
        $thirty = array_map(static fn (int $n): array => ['name' => 'Parametr '.$n, 'value' => 'wartość '.$n], range(1, 30));

        $view = $this->createProduct(['parameters' => $thirty]);
        self::assertSame($thirty, $view['parameters']);

        $this->api('PUT', self::PRODUCTS.'/'.self::string($view['id']), $this->editBody($view, ['parameters' => [...$thirty, ['name' => 'Parametr 31', 'value' => 'x']]]));

        self::assertSame([['propertyPath' => 'parameters', 'title' => 'Produkt może mieć najwyżej 30 parametrów.']], $this->violations());
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$view['id']]));
    }

    /**
     * T1 is checked on the value as it came, before the trim of the ends: a character a trim would drop is refused,
     * not cut off (PRODUCT-DATA, criterion 9a).
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function forbiddenAtTheEdges(): iterable
    {
        yield 'a tab at the end of the brand' => [['brand' => "Hallen\t"], 'brand', 'Marka zawiera niedozwolone znaki.'];
        yield 'U+2028 at the start of the brand' => [['brand' => "\u{2028}Hallen"], 'brand', 'Marka zawiera niedozwolone znaki.'];
        yield 'a carriage return at the end of the description' => [['description' => "Opis\r"], 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'U+000D inside the description' => [['description' => "Pierwszy\r\nDrugi"], 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'U+2028 at the end of the description' => [['description' => "Opis\u{2028}"], 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'U+2028 at the end of a value' => [['parameters' => [['name' => 'Waga', 'value' => "2 kg\u{2028}"]]], 'parameters[0].value', 'Wartość parametru zawiera niedozwolone znaki.'];
        yield 'U+0085 at the start of a value' => [['parameters' => [['name' => 'Waga', 'value' => "\u{0085}2 kg"]]], 'parameters[0].value', 'Wartość parametru zawiera niedozwolone znaki.'];
        yield 'a tab at the start of a name' => [['parameters' => [['name' => 'Kolor', 'value' => 'x'], ['name' => "\tWaga", 'value' => '1']]], 'parameters[1].name', 'Nazwa parametru zawiera niedozwolone znaki.'];
        yield 'a line feed at the end of a value' => [['parameters' => [['name' => 'Waga', 'value' => "2 kg\n"]]], 'parameters[0].value', 'Wartość parametru zawiera niedozwolone znaki.'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('forbiddenAtTheEdges')]
    public function testACharacterOfT1AtTheEdgeIsAViolationAtTheFieldAndNothingIsSaved(array $change, string $path, string $title): void
    {
        $this->api('POST', self::PRODUCTS, $this->body($change));

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));

        $created = $this->createProduct(self::content());
        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, $change));

        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());
        self::assertSame([1, self::TRIMMED['brand']], [$this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]), $this->connection->fetchOne('SELECT brand FROM catalog_product WHERE id = ?', [$created['id']])]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTexts(): iterable
    {
        yield from InputValues::blankUnicodeTexts();
    }

    #[DataProvider('blankTexts')]
    public function testTextsOfOnlyWhiteSpaceAreNoBrandAndNoDescriptionButNoParameter(string $blank): void
    {
        $view = $this->createProduct(['brand' => $blank, 'description' => $blank]);

        self::assertSame(['', ''], [$view['brand'], $view['description']]);

        $this->api('POST', self::PRODUCTS, $this->body(['slug' => 'inny', 'parameters' => [['name' => $blank, 'value' => 'x'], ['name' => 'Waga', 'value' => $blank]]]));

        self::assertSame([
            ['propertyPath' => 'parameters[0].name', 'title' => 'Nazwa parametru musi mieć od 1 do 60 znaków.'],
            ['propertyPath' => 'parameters[1].value', 'title' => 'Wartość parametru musi mieć od 1 do 200 znaków.'],
        ], $this->violations());
    }

    public function testAProductStoredBeforeTheFieldsExistedIsReadAndSaved(): void
    {
        $created = $this->createProduct(self::content());
        $id = self::string($created['id']);
        // The state ADD COLUMN … DEFAULT gives the rows of before the migration.
        $this->connection->executeStatement('UPDATE catalog_product SET brand = DEFAULT, description = DEFAULT, parameters = DEFAULT WHERE id = ?', [$id]);
        $published = $this->api('POST', self::PRODUCTS.'/'.$id.'/publish', ['version' => 1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['', '', []], [$published['brand'], $published['description'], $published['parameters']]);

        $view = $this->api('GET', self::PRODUCTS.'/'.$id);
        self::assertSame(['', '', []], [$view['brand'], $view['description'], $view['parameters']]);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertSame(['', '', []], [$page['brand'], $page['description'], $page['parameters']]);

        $saved = $this->api('PUT', self::PRODUCTS.'/'.$id, $this->editBody($view, ['brand' => 'Hallen']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Hallen', $saved['brand']);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function storedOutsideTheRule(): iterable
    {
        yield 'a tab in the brand' => ['brand', "Hal\tlen", 'brand', 'Marka zawiera niedozwolone znaki.'];
        yield 'a carriage return in the description' => ['description', "Opis\r\ndalej", 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'U+2028 in a value' => ['parameters', '[{"name": "Waga", "value": "2 kg
"}]', 'parameters[0].value', 'Wartość parametru zawiera niedozwolone znaki.'];
        yield 'a repeated name' => ['parameters', '[{"name": "Waga", "value": "1"}, {"name": "waga", "value": "2"}]', 'parameters[1].name', 'Parametr o tej nazwie już jest na liście.'];
        yield 'an empty value' => ['parameters', '[{"name": "Waga", "value": ""}]', 'parameters[0].value', 'Wartość parametru musi mieć od 1 do 200 znaków.'];
    }

    #[DataProvider('storedOutsideTheRule')]
    public function testAContentStoredOutsideTheRuleIsReadAsItIsAndItsEditHasToFixIt(string $column, string $stored, string $path, string $title): void
    {
        $created = $this->publish($this->createProduct());
        $id = self::string($created['id']);
        $this->connection->executeStatement(\sprintf('UPDATE catalog_product SET %s = ? WHERE id = ?', $column), [$stored, $id]);

        $view = $this->api('GET', self::PRODUCTS.'/'.$id);
        self::assertResponseStatusCodeSame(200);
        $page = $this->api('GET', '/api/v1/products/buty-biegowe', token: null);
        self::assertResponseStatusCodeSame(200);
        $expected = 'parameters' === $column ? json_decode($stored, true, flags: \JSON_THROW_ON_ERROR) : $stored;
        self::assertSame($expected, $view[$column]);
        self::assertSame($expected, $page[$column]);

        $this->api('PUT', self::PRODUCTS.'/'.$id, $this->editBody($view));
        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());

        // A command that loads the product without changing its content goes through and keeps it as it was.
        $this->api('POST', self::PRODUCTS.'/'.$id.'/unpublish', ['version' => $view['version']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($expected, $this->api('GET', self::PRODUCTS.'/'.$id)[$column]);
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
