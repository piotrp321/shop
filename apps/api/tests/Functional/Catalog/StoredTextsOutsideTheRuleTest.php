<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Query\ListVariantIdentities;
use App\Catalog\Application\Query\VariantIdentity;
use App\Catalog\Domain\Product\IdentityText;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Orders\CatalogVariant;
use App\DemoData\Orders\DbalOrderCatalog;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Cart\CartFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
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
     * @return iterable<string, array{string, string, string}>
     */
    public static function textsWithCharactersTheRuleTrims(): iterable
    {
        foreach (['U+200B' => "\u{200B}", 'U+2060' => "\u{2060}", 'U+FEFF' => "\u{FEFF}", 'U+00AD' => "\u{AD}"] as $label => $edge) {
            yield "the axis ending with $label" => ['axis', 'Rozmiar'.$edge, 'Rozmiar'];
            yield "a value starting with $label" => ['value', $edge.'M', 'M'];
        }
    }

    /**
     * The identity of the axes and the variants of a published product (K5) is compared on texts trimmed by the rule
     * (T2): an axis or a value stored with a character the rule trims is the same axis or value without it, so the
     * product is saved from its view unchanged and keeps the trimmed text.
     */
    #[DataProvider('textsWithCharactersTheRuleTrims')]
    public function testAPublishedProductStoredWithCharactersTheRuleTrimsIsSavedFromItsView(string $field, string $text, string $trimmed): void
    {
        $id = $this->publishedWith($field, $text);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->storedAfter($id, $field, $trimmed), $this->stored($id));
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool, string}>
     */
    public static function textsToCorrect(): iterable
    {
        $axis = 'Nazwa osi zawiera niedozwolone znaki.';
        $value = 'Wartość wariantu zawiera niedozwolone znaki.';
        yield 'U+009F in the axis' => ['axis', "Roz\u{9F}miar", 'variantAxes[0]', $axis, false, 'Rozmiar'];
        yield 'a tab in the axis' => ['axis', "Roz\tmiar", 'variantAxes[0]', $axis, false, 'Rozmiar'];
        yield 'U+200B as the axis' => ['axis', "\u{200B}", 'variantAxes[0]', 'Nazwa osi musi mieć od 1 do 30 znaków.', false, 'Rozmiar'];
        yield 'U+009F in a value' => ['value', "M\u{9F}", 'variants[0].values[0]', $value, false, 'M'];
        yield 'a tab in a value' => ['value', "M\tL", 'variants[0].values[0]', $value, false, 'M L'];
        yield 'U+200B as a value' => ['value', "\u{200B}", 'variants[0].values[0]', 'Wartość musi mieć od 1 do 40 znaków.', false, 'M'];
        yield 'U+009F in a value of a variant in stock' => ['value', "M\u{9F}", 'variants[0].values[0]', $value, true, 'M'];
    }

    /**
     * A stored axis or value the rule refuses is a 422 at its field until it is corrected (K5): the correction is the
     * same text without the forbidden characters and white space, saved also for a variant in stock. From then on the
     * corrected text is locked as any other.
     */
    #[DataProvider('textsToCorrect')]
    public function testAPublishedProductStoredWithATextTheRuleRefusesIsSavedOnceTheTextIsCorrected(string $field, string $text, string $path, string $title, bool $inStock, string $corrected): void
    {
        $id = $this->publishedWith($field, $text);
        if ($inStock) {
            $this->api('POST', '/api/v1/admin/variants/'.$this->variant($id, 0).'/stock/receipts', ['quantity' => 5, 'note' => null], server: ['HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()]);
            self::assertResponseStatusCodeSame(201);
        }
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view));
        self::assertSame([['propertyPath' => $path, 'title' => $title]], $this->violations());

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, $this->changed($view, $field, $corrected)));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->storedAfter($id, $field, $corrected), $this->stored($id));

        $saved = $this->api('GET', '/api/v1/admin/products/'.$id);
        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($saved, $this->changed($saved, $field, 'axis' === $field ? 'Kolor' : 'XL')));
        $this->assertProblem(409, 'variant-locked');
    }

    /**
     * @return iterable<string, array{string, string, string, int, bool}>
     */
    public static function storedTextsAndTheirChanges(): iterable
    {
        // The text the rule refuses for a forbidden character keeps the text without forbidden characters and white space.
        yield 'an axis with a tab, corrected' => ['axis', "Roz\tmiar", 'Rozmiar', 200, false];
        yield 'an axis with a tab, corrected with a space' => ['axis', "Roz\tmiar", 'Roz miar', 200, true];
        yield 'an axis with a tab, renamed' => ['axis', "Roz\tmiar", 'Materiał', 409, true];
        yield 'an axis with U+009F, corrected' => ['axis', "Roz\u{9F}miar", 'Rozmiar', 200, false];
        yield 'an axis with U+009F, renamed' => ['axis', "Roz\u{9F}miar", 'Materiał', 409, false];
        yield 'a value with U+009F, corrected' => ['value', "czar\u{9F}ne", 'czarne', 200, true];
        yield 'a value with U+009F, corrected with a space' => ['value', "czar\u{9F}ne", 'czar ne', 200, false];
        yield 'a value with U+009F, changed' => ['value', "czar\u{9F}ne", 'zielone', 409, true];
        yield 'a value with a tab, corrected with a space' => ['value', "M\tXL", 'M XL', 200, false];
        yield 'a value with a tab, corrected without it' => ['value', "M\tXL", 'MXL', 200, true];
        yield 'a value with a tab, changed' => ['value', "M\tXL", 'M/XL', 409, false];
        // A text without a visible character has no text to keep.
        yield 'an axis of U+200B, named' => ['axis', "\u{200B}", 'Materiał', 200, true];
        yield 'an axis of U+3164, named' => ['axis', "\u{3164}", 'Materiał', 200, false];
        yield 'a value of U+200B, given' => ['value', "\u{200B}", 'zielone', 200, true];
        yield 'a value of U+3164, given' => ['value', "\u{3164}", 'zielone', 200, false];
    }

    /**
     * A stored axis or value the rule refuses for a forbidden character may only be corrected (K5): the text without the
     * forbidden characters and white space is kept, any other text is a change of the identity and changes nothing. A
     * stored text without a visible character has no text to keep.
     */
    #[DataProvider('storedTextsAndTheirChanges')]
    public function testAPublishedProductStoredWithATextTheRuleRefusesTakesOnlyItsCorrection(string $field, string $stored, string $given, int $status, bool $inStock): void
    {
        $id = $this->publishedWith($field, $stored);
        if ($inStock) {
            $this->api('POST', '/api/v1/admin/variants/'.$this->variant($id, 0).'/stock/receipts', ['quantity' => 24, 'note' => null], server: ['HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()]);
            self::assertResponseStatusCodeSame(201);
        }
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, $this->changed($view, $field, $given)));

        if (409 === $status) {
            $detail = self::string($this->assertProblem(409, 'variant-locked')['detail'] ?? null);
            // The detail names the text as a person reads it and says what the correction may be (K5).
            self::assertSame(0, preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $detail), 'the detail has a character the rule forbids');
            self::assertStringContainsString('znaki niedozwolone: usuń je albo zastąp odstępem', $detail);
            self::assertSame($this->storedAfter($id, $field, $stored), $this->stored($id));
            self::assertSame($view['version'], $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$id]));

            return;
        }
        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->storedAfter($id, $field, $given), $this->stored($id));
    }

    /**
     * Of two axes, the one stored with a forbidden character is corrected while the values of the variants stay; a
     * reordering of the axes stays locked, and so does a renaming of the other axis.
     */
    public function testTheCorrectionOfOneOfTwoAxesKeepsTheOtherLocked(): void
    {
        $created = $this->publish($this->createProduct([
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [['variantId' => null, 'values' => ['czarne', 'M'], 'sku' => '', 'ean' => ''], ['variantId' => null, 'values' => ['czarne', 'L'], 'sku' => '', 'ean' => '']],
        ]));
        $id = self::string($created['id']);
        $this->connection->executeStatement("UPDATE catalog_product SET variant_axes = jsonb_build_array('Kol'||chr(9)||'or', 'Rozmiar') WHERE id = ?", [$id]);
        $this->entityManager->clear();
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);
        $variants = self::list($view['variants']);

        $swapped = array_map(static fn (array $variant): array => [...$variant, 'values' => array_reverse(self::list($variant['values'] ?? null))], $variants);
        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Rozmiar', 'Kolor'], 'variants' => $swapped]));
        self::assertSame('Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.', $this->assertProblem(409, 'variant-locked')['detail'] ?? null);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', 'Wielkość']]));
        $this->assertProblem(409, 'variant-locked');

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', 'Rozmiar']]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Kolor', 'Rozmiar'], json_decode(self::string($this->connection->fetchOne('SELECT variant_axes FROM catalog_product WHERE id = ?', [$id])), true));
    }

    /**
     * @return iterable<string, array{list<string>, string}> two axis names stored before the rule of texts that repeat
     *                                                       each other once the forbidden characters are spaces and
     *                                                       the ends are trimmed (T2), and what an edit that keeps
     *                                                       them gets at the second axis
     */
    public static function storedAxesThatRepeatEachOther(): iterable
    {
        yield 'U+200B at the end of the second' => [['Kolor', "Kolor\u{200B}"], 'Nazwa osi już jest użyta.'];
        yield 'U+009F at the end of the second' => [['Kolor', "Kolor\u{9F}"], 'Nazwa osi zawiera niedozwolone znaki.'];
        yield 'U+009F before the one letter of the second' => [['A', "\u{9F}A"], 'Nazwa osi zawiera niedozwolone znaki.'];
    }

    /**
     * Two axis names the rule before the rule of texts took as different are one name now: an edit that keeps them is
     * a 422 at the second axis, the first stays locked, and the product is saved once the second axis takes a name
     * that does not repeat the first, with the other changes of the edit; the axes keep their count and order, the
     * variants their identifiers and values (E7). Then K5 locks both names again: a new name of either is refused, a
     * change of case is not.
     *
     * @param list<string> $stored
     */
    #[DataProvider('storedAxesThatRepeatEachOther')]
    public function testTheSecondOfTwoAxesStoredThatRepeatEachOtherTakesANewNameOnce(array $stored, string $kept): void
    {
        $id = $this->publishedWithRepeatedAxes($stored);
        $first = IdentityText::shown($stored[0]);
        $variants = $this->storedVariants($id);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['shortDescription' => 'Nowy opis']));
        self::assertSame([['propertyPath' => 'variantAxes[1]', 'title' => $kept]], $this->violations());
        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Barwa', 'Rozmiar']]));
        $this->assertProblem(409, 'variant-locked', \sprintf('Oś „%s” nie może zmienić nazwy po pierwszej publikacji. Popraw tylko wielkość liter. Nową nazwę może dostać tylko druga oś, która powtarza tę nazwę.', $first));
        self::assertSame($stored, $this->storedAxes($id));

        $saved = $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['shortDescription' => 'Nowy opis', 'variantAxes' => [$first, 'Rozmiar']]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$first, 'Rozmiar'], $this->storedAxes($id));
        self::assertSame('Nowy opis', $this->connection->fetchOne('SELECT short_description FROM catalog_product WHERE id = ?', [$id]));
        self::assertSame($variants, $this->storedVariants($id));

        foreach ([[[$first, 'Materiał'], 'Rozmiar'], [['Barwa', 'Rozmiar'], $first]] as [$axes, $locked]) {
            $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($saved, ['variantAxes' => $axes]));
            $this->assertProblem(409, 'variant-locked', \sprintf('Oś „%s” nie może zmienić nazwy po pierwszej publikacji. Popraw tylko wielkość liter.', $locked));
        }
        self::assertSame([$first, 'Rozmiar'], $this->storedAxes($id));

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($saved, ['variantAxes' => [mb_strtolower($first), 'ROZMIAR']]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([mb_strtolower($first), 'ROZMIAR'], $this->storedAxes($id));
        self::assertSame($variants, $this->storedVariants($id));
    }

    /**
     * @return iterable<string, array{string, string, string}> the stored second axis name with a forbidden character,
     *                                                         its name in the detail and its correction that does not
     *                                                         repeat the first
     */
    public static function secondAxesThatDoNotRepeatTheFirst(): iterable
    {
        yield 'U+009F inside' => ["Kol\u{9F}or", 'Kolor', 'Kol or'];
        yield 'U+009F before a space inside' => ["Ko\u{9F} lor", 'Ko lor', 'Ko lor'];
    }

    /**
     * A second axis name with a forbidden character that does not repeat the first once the character is a space has no
     * new name (K5): it takes only its correction, and the correction without the space repeats the first.
     */
    #[DataProvider('secondAxesThatDoNotRepeatTheFirst')]
    public function testASecondAxisWithAForbiddenCharacterThatDoesNotRepeatTheFirstTakesOnlyItsCorrection(string $stored, string $shown, string $correction): void
    {
        $id = $this->publishedWithRepeatedAxes(['Kolor', $stored]);
        $variants = $this->storedVariants($id);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', 'Materiał']]));
        $this->assertProblem(409, 'variant-locked', \sprintf('Oś „%s” nie może zmienić nazwy po pierwszej publikacji. Zapisana nazwa ma znaki niedozwolone: usuń je albo zastąp odstępem i popraw tylko wielkość liter.', $shown));
        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', 'Kolor']]));
        self::assertSame([['propertyPath' => 'variantAxes[1]', 'title' => 'Nazwa osi już jest użyta.']], $this->violations());
        self::assertSame(['Kolor', $stored], $this->storedAxes($id));

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', $correction]]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Kolor', $correction], $this->storedAxes($id));
        self::assertSame($variants, $this->storedVariants($id));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function editsThatKeepTheRepeatedAxis(): iterable
    {
        yield 'no change' => [[]];
        yield 'another field changed' => [['shortDescription' => 'Nowy opis']];
        yield 'the second in another case' => [['variantAxes' => ['Kolor', 'KOLOR']]];
        yield 'the second with U+200B at its end' => [['variantAxes' => ['Kolor', "Kolor\u{200B}"]]];
        yield 'the second with U+2060 at its end' => [['variantAxes' => ['Kolor', "Kolor\u{2060}"]]];
        yield 'the second with U+FEFF at its start' => [['variantAxes' => ['Kolor', "\u{FEFF}kolor"]]];
        yield 'the second with U+00AD at its end' => [['variantAxes' => ['Kolor', "Kolor\u{AD}"]]];
        yield 'the second with NBSP at its start' => [['variantAxes' => ['Kolor', "\u{A0}Kolor"]]];
    }

    /**
     * The second name still repeating the first is the field to fix: 422 at the second axis, nothing saved.
     *
     * @param array<string, mixed> $changes
     */
    #[DataProvider('editsThatKeepTheRepeatedAxis')]
    public function testTheSecondOfTwoAxesStoredWithOneIdentityIsARepeatedAxisUntilItIsRenamed(array $changes): void
    {
        $id = $this->publishedWithRepeatedAxes();
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, $changes));

        self::assertSame([['propertyPath' => 'variantAxes[1]', 'title' => 'Nazwa osi już jest użyta.']], $this->violations());
        self::assertSame(['Kolor', "Kolor\u{200B}"], $this->storedAxes($id));
        self::assertSame($view['version'], $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{list<string>, bool, string}> the axes, whether the values of the variants follow
     *                                                             a reordering, and the detail
     */
    public static function changesOfTheFirstOfTwoRepeatedAxes(): iterable
    {
        $first = 'Oś „Kolor” nie może zmienić nazwy po pierwszej publikacji. Popraw tylko wielkość liter. Nową nazwę może dostać tylko druga oś, która powtarza tę nazwę.';

        yield 'the first renamed' => [['Barwa', 'Kolor'], false, $first];
        yield 'both renamed' => [['Barwa', 'Rozmiar'], false, $first];
        yield 'reordered, the second renamed' => [['Rozmiar', 'Kolor'], true, $first];
    }

    /**
     * The first of two axes stored with one identity keeps its lock (K5), also when the second takes a new name in
     * the same edit or the axes are reordered.
     *
     * @param list<string> $axes
     */
    #[DataProvider('changesOfTheFirstOfTwoRepeatedAxes')]
    public function testTheFirstOfTwoAxesStoredWithOneIdentityStaysLocked(array $axes, bool $reordered, string $detail): void
    {
        $id = $this->publishedWithRepeatedAxes();
        $variants = $this->storedVariants($id);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);
        $changes = ['variantAxes' => $axes];
        if ($reordered) {
            $changes['variants'] = array_map(static fn (array $variant): array => [...$variant, 'values' => array_reverse(self::list($variant['values'] ?? null))], self::list($view['variants']));
        }

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, $changes));

        $this->assertProblem(409, 'variant-locked', $detail);
        self::assertSame(['Kolor', "Kolor\u{200B}"], $this->storedAxes($id));
        self::assertSame($variants, $this->storedVariants($id));
    }

    /**
     * An axis is not removed after the first publication, also of two axes stored with one identity; a third axis is
     * over the limit of two.
     */
    public function testTwoAxesStoredWithOneIdentityAreNotRemovedOrAdded(): void
    {
        $id = $this->publishedWithRepeatedAxes();
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);
        $single = array_map(static fn (array $variant): array => [...$variant, 'values' => [self::list($variant['values'] ?? null)[0]]], self::list($view['variants']));

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor'], 'variants' => $single]));
        $this->assertProblem(409, 'variant-locked', 'Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.');

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', 'Rozmiar', 'Materiał']]));
        self::assertResponseStatusCodeSame(422);

        self::assertSame(['Kolor', "Kolor\u{200B}"], $this->storedAxes($id));
    }

    /**
     * Only two stored names with one identity open the new name of the second axis: a request that repeats a stored
     * name, or sends two names with one identity, does not.
     */
    public function testTheSecondOfTwoAxesStoredWithTwoIdentitiesStaysLocked(): void
    {
        $id = self::string($this->publish($this->createProduct(self::twoAxes()))['id']);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', 'Materiał']]));
        $this->assertProblem(409, 'variant-locked', 'Oś „Rozmiar” nie może zmienić nazwy po pierwszej publikacji. Popraw tylko wielkość liter.');

        foreach (['KOLOR', "Kolor\u{200B}"] as $repeated) {
            $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variantAxes' => ['Kolor', $repeated]]));
            self::assertSame([['propertyPath' => 'variantAxes[1]', 'title' => 'Nazwa osi już jest użyta.']], $this->violations());
        }

        self::assertSame(['Kolor', 'Rozmiar'], $this->storedAxes($id));
    }

    /**
     * A value stored with a forbidden character whose correction repeats another variant is a repeated variant.
     */
    public function testACorrectionThatRepeatsAnotherVariantIsARepeatedVariant(): void
    {
        $id = $this->publishedWith('value', "L\u{9F}");
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, $this->changed($view, 'value', 'L')));

        self::assertSame([['propertyPath' => 'variants[1].values', 'title' => 'Taki wariant już istnieje.']], $this->violations());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function changesOfIdentity(): iterable
    {
        yield 'a renamed axis' => ['axis', 'Rozmiar', 'Kolor'];
        yield 'a changed value' => ['value', 'M', 'XL'];
        yield 'a renamed axis stored with U+200B at its end' => ['axis', "Rozmiar\u{200B}", 'Kolor'];
        yield 'a changed value stored with U+FEFF at its start' => ['value', "\u{FEFF}M", 'XL'];
    }

    /**
     * A stored text the rule takes keeps its identity after the first publication (K5), also with characters the rule
     * trims at its ends.
     */
    #[DataProvider('changesOfIdentity')]
    public function testAPublishedProductStillRefusesAChangeOfTheIdentityOfItsVariants(string $field, string $stored, string $changed): void
    {
        $id = $this->publishedWith($field, $stored);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, $this->changed($view, $field, $changed)));

        $detail = self::string($this->assertProblem(409, 'variant-locked')['detail'] ?? null);
        self::assertSame(0, preg_match('/[\p{Cc}\p{Cf}]/u', $detail), 'the detail has a character the rule forbids or trims');
        self::assertStringContainsString('Popraw tylko wielkość liter', $detail);
        self::assertSame($this->storedAfter($id, $field, $stored), $this->stored($id));
    }

    /**
     * Two variants stored with values that are one value once trimmed are a repeated combination when the product is
     * saved.
     */
    public function testAValueThatRepeatsAnotherOnceTrimmedIsARepeatedVariant(): void
    {
        $id = $this->publishedWith('value', 'M');
        $this->connection->executeStatement('UPDATE catalog_product_variant SET option_values = jsonb_build_array(?::text) WHERE product_id = ? AND position = 1', ["\u{200B}m", $id]);
        $this->entityManager->clear();
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view));

        self::assertSame([['propertyPath' => 'variants[1].values', 'title' => 'Taki wariant już istnieje.']], $this->violations());
    }

    /**
     * Three variants stored with values that are one value once trimmed: each one after the first is a repeated
     * combination, and the way out is the same as for two: the stock of a variant to remove corrected to zero, then
     * the product saved without the repeated variants, the kept one with its identifier.
     */
    public function testThreeVariantsStoredWithOneValueAreSavedOnceTheRepeatedOnesAreRemoved(): void
    {
        $id = self::string($this->publish($this->createProduct([
            'variants' => [['variantId' => null, 'values' => ['M'], 'sku' => '', 'ean' => ''], ['variantId' => null, 'values' => ['L'], 'sku' => '', 'ean' => ''], ['variantId' => null, 'values' => ['XL'], 'sku' => '', 'ean' => '']],
        ]))['id']);
        foreach ([1 => "M\u{200B}", 2 => "\u{FEFF}m"] as $position => $value) {
            $this->connection->executeStatement('UPDATE catalog_product_variant SET option_values = jsonb_build_array(?::text) WHERE product_id = ? AND position = ?', [$value, $id, $position]);
        }
        $this->entityManager->clear();
        $kept = $this->variant($id, 0);
        $inStock = $this->variant($id, 2);
        $receipt = $this->api('POST', '/api/v1/admin/variants/'.$inStock.'/stock/receipts', ['quantity' => 5, 'note' => null], server: ['HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()]);
        self::assertResponseStatusCodeSame(201);
        $view = $this->api('GET', '/api/v1/admin/products/'.$id);

        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view));
        self::assertSame([
            ['propertyPath' => 'variants[1].values', 'title' => 'Taki wariant już istnieje.'],
            ['propertyPath' => 'variants[2].values', 'title' => 'Taki wariant już istnieje.'],
        ], $this->violations());

        $first = [self::list($view['variants'])[0]];
        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variants' => $first]));
        $this->assertProblem(409, 'variant-in-use');

        $this->api('POST', '/api/v1/admin/variants/'.$inStock.'/stock/corrections', ['physical' => 0, 'version' => $receipt['version'], 'reason' => 'other', 'note' => 'Powtórzony wariant']);
        self::assertResponseStatusCodeSame(200);
        $this->api('PUT', '/api/v1/admin/products/'.$id, $this->editBody($view, ['variants' => $first]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['id' => $kept, 'option_values' => '["M"]']], $this->connection->fetchAllAssociative('SELECT id, option_values FROM catalog_product_variant WHERE product_id = ?', [$id]));
    }

    /**
     * The reads of variants other modules ask Catalog for (the summaries of the lines of a cart, the variants of the
     * stock list of the panel, the identities of the variants for the seeds) take a stored value outside the rule.
     */
    public function testTheReadsOfVariantsForOtherModulesTakeAStoredValueOutsideTheRule(): void
    {
        $id = $this->publishedWith('value', "M\tL");
        $variantId = $this->variant($id, 0);

        $cart = $this->api('GET', '/api/v1/guest-carts/'.CartFixture::cart($this->connection, lines: [[$variantId, 1]]), token: null);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue(self::holds($cart, "M\tL"), 'the line of the cart names the variant');

        $stock = $this->api('GET', '/api/v1/admin/stock');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue(self::holds($stock, "M\tL"), 'the stock list names the variant');

        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $identities = $bus->dispatch(new ListVariantIdentities(null, 100))->last(HandledStamp::class)?->getResult();
        self::assertIsArray($identities);
        $keys = [];
        foreach ($identities as $identity) {
            self::assertInstanceOf(VariantIdentity::class, $identity);
            $keys[$identity->variantId] = $identity->key;
        }
        self::assertSame('buty-biegowe/m-l', $keys[$variantId] ?? null);
    }

    /**
     * The generator of the demo orders reads the variants of the generated catalog from the tables, also a value
     * stored outside the rule.
     */
    public function testTheGeneratorOfDemoOrdersReadsAStoredValueOutsideTheRule(): void
    {
        $namespace = '0193a0c4-7c1a-7c4e-9a6e-3f1d2b4c5a70';
        $variantId = Uuid::v7()->toRfc4122();
        $product = ProductFactory::createOne([
            'id' => DemoUuid::v5($namespace, 'product:0'),
            'mainCategory' => $this->category,
            'variantAxes' => ['Rozmiar'],
            'variants' => [['id' => $variantId, 'values' => ['M']]],
        ]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());
        $this->connection->executeStatement('UPDATE catalog_product_variant SET option_values = jsonb_build_array(?::text) WHERE id = ?', ["M\u{9F}", $variantId]);

        $products = new DbalOrderCatalog($this->connection, $namespace)->products([0]);

        self::assertSame(["M\u{9F}"], array_map(static fn (CatalogVariant $variant): string => $variant->label, $products[0]->variants));
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
     * A published product (axis Rozmiar, variants M and L) with the text stored in its field past the domain.
     */
    private function publishedWith(string $field, string $text): string
    {
        $id = self::string($this->publish($this->createProduct())['id']);
        $this->store($id, $field, $text);

        return $id;
    }

    /**
     * @return array<string, mixed> the changes of a product with the axes Kolor and Rozmiar and two variants
     */
    private static function twoAxes(): array
    {
        return [
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [['variantId' => null, 'values' => ['czarne', 'M'], 'sku' => '', 'ean' => ''], ['variantId' => null, 'values' => ['białe', 'L'], 'sku' => '', 'ean' => '']],
        ];
    }

    /**
     * A published product with the two axes, Kolor and Kolor U+200B by default, past the domain, as the rule before the
     * rule of texts stored two different names.
     *
     * @param list<string> $axes
     */
    private function publishedWithRepeatedAxes(array $axes = ['Kolor', "Kolor\u{200B}"]): string
    {
        $id = self::string($this->publish($this->createProduct(self::twoAxes()))['id']);
        $this->connection->executeStatement('UPDATE catalog_product SET variant_axes = jsonb_build_array(?::text, ?::text) WHERE id = ?', [$axes[0], $axes[1], $id]);
        $this->entityManager->clear();

        return $id;
    }

    /**
     * @return mixed the stored names of the axes
     */
    private function storedAxes(string $id): mixed
    {
        return json_decode(self::string($this->connection->fetchOne('SELECT variant_axes FROM catalog_product WHERE id = ?', [$id])), true);
    }

    /**
     * @return list<array<string, mixed>> the identifiers, positions and values of the stored variants
     */
    private function storedVariants(string $id): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, position, option_values FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [$id]);
    }

    private function variant(string $productId, int $position): string
    {
        return self::string($this->connection->fetchOne('SELECT id FROM catalog_product_variant WHERE product_id = ? AND position = ?', [$productId, $position]));
    }

    /**
     * The changes of an edit that put the text in the field: the first axis, or the first value of the first variant.
     *
     * @param array<mixed> $view
     *
     * @return array<string, mixed>
     */
    private function changed(array $view, string $field, string $text): array
    {
        if ('axis' === $field) {
            return ['variantAxes' => [$text]];
        }
        $variants = self::list($view['variants']);
        $variants[0]['values'] = [$text];

        return ['variants' => $variants];
    }

    /**
     * @param array<mixed> $answer
     */
    private static function holds(array $answer, string $text): bool
    {
        foreach ($answer as $value) {
            if ($value === $text || \is_array($value) && self::holds($value, $text)) {
                return true;
            }
        }

        return false;
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
