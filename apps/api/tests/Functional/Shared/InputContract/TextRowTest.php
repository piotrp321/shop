<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use PHPUnit\Framework\TestCase;

/**
 * The executions each kind of row of the contract of texts gives (the table of executions of the spec, "Test kontraktu
 * tekstów"), also for the kinds no module has a row of yet.
 */
final class TextRowTest extends TestCase
{
    private const string OPERATION = 'POST /api/v1/example';

    public function testARequiredTextIsRefusedEmptyOrWithAForbiddenCharacterAndKeptTrimmedOrPreserved(): void
    {
        $executions = $this->executions(TextRow::required(self::OPERATION, InputCase::STAFF, InputContractFixtures::NONE, 'name', 'Kurier', ['name' => 'Kurier'],
            empty: 'Pusta.', forbidden: 'Znaki.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', [])));

        self::assertSame([
            'empty: U+00A0' => ['empty', "\u{A0}", 422, 'Pusta.', false, null],
            'empty: U+200B' => ['empty', "\u{200B}", 422, 'Pusta.', false, null],
            'empty: U+3164' => ['empty', "\u{3164}", 422, 'Pusta.', false, null],
            'forbidden: U+0000 inside' => ['forbidden', "Kur\u{0}ier", 422, 'Znaki.', false, null],
            'forbidden: U+009F inside' => ['forbidden', "Kur\u{9F}ier", 422, 'Znaki.', false, null],
            'forbidden: U+2028 inside' => ['forbidden', "Kur\u{2028}ier", 422, 'Znaki.', false, null],
            'forbidden: U+2028 at the end' => ['forbidden', "Kurier\u{2028}", 422, 'Znaki.', false, null],
            'edges: between U+00A0' => ['edges', "\u{A0}Kurier\u{A0}", null, null, true, 'Kurier'],
            'edges: between U+200B' => ['edges', "\u{200B}Kurier\u{200B}", null, null, true, 'Kurier'],
            'preserved: ending with the flag of England' => ['preserved', "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", null, null, true, "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"],
            'preserved: an emoji with U+200D' => ['preserved', "Kurier \u{1F469}\u{200D}\u{1F4BB}", null, null, true, "Kurier \u{1F469}\u{200D}\u{1F4BB}"],
            'preserved: Polish letters' => ['preserved', 'Zażółć gęślą jaźń', null, null, true, 'Zażółć gęślą jaźń'],
            'wrong type: a number' => ['wrong type', 123, 422, 'Typ.', false, null],
            'wrong type: a list' => ['wrong type', ['tekst'], 422, 'Typ.', false, null],
        ], $executions);
    }

    public function testAnOptionalTextStoresNoValueWhenEmptyAndTakesTheCharactersOfItsField(): void
    {
        $executions = $this->executions(TextRow::optional(self::OPERATION, InputCase::STAFF, InputContractFixtures::NONE, 'note', 'Notatka', ['note' => null],
            noValue: null, forbidden: 'Znaki.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', []), allowed: "\t\n"));

        self::assertSame(['empty', "\u{A0}", null, null, true, null], $executions['empty: U+00A0']);
        self::assertSame(['empty', "\u{3164}", null, null, true, null], $executions['empty: U+3164']);
        self::assertSame(['allowed in the field', "Not\tatka", null, null, true, "Not\tatka"], $executions['allowed in the field: U+0009 inside']);
        self::assertSame(['allowed in the field', "Not\natka", null, null, true, "Not\natka"], $executions['allowed in the field: U+000A inside']);
        self::assertSame(['edges', "\u{200B}Notatka\u{200B}", null, null, true, 'Notatka'], $executions['edges: between U+200B']);
        self::assertSame(['forbidden', "Notatka\u{2028}", 422, 'Znaki.', false, null], $executions['forbidden: U+2028 at the end']);
    }

    /**
     * The cases of the row come in place of the classes forbidden, edges and preserved; the mechanism adds the valid
     * value of the first accepted case with U+2028 at its end, which the row cannot leave out: T1 looks at the value as
     * it came, so the trim of the ends must not drop the character before the format is checked.
     */
    public function testAFieldWithAFormatRunsItsCasesInPlaceOfTheClassesOfText(): void
    {
        $executions = $this->executions(TextRow::format(self::OPERATION, InputCase::STAFF, InputContractFixtures::NONE, 'address.postalCode', ['address' => ['postalCode' => '00-950']],
            empty: 'Wymagany.', forbidden: 'Format.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', []), cases: [
                ['00950', '00950', 422, 'Format.'],
                ['00-950 between U+00A0', "\u{A0}00-950\u{A0}", 201, '00-950'],
                ['00-950', '00-950', 201, '00-950'],
            ]));

        self::assertSame([
            'empty: U+00A0' => ['empty', "\u{A0}", 422, 'Wymagany.', false, null],
            'empty: U+200B' => ['empty', "\u{200B}", 422, 'Wymagany.', false, null],
            'empty: U+3164' => ['empty', "\u{3164}", 422, 'Wymagany.', false, null],
            'format: 00950' => ['format', '00950', 422, 'Format.', false, null],
            'format: 00-950 between U+00A0' => ['format', "\u{A0}00-950\u{A0}", 201, null, true, '00-950'],
            'format: 00-950' => ['format', '00-950', 201, null, true, '00-950'],
            'forbidden: U+2028 at the end of a valid value' => ['forbidden', "\u{A0}00-950\u{A0}\u{2028}", 422, 'Format.', false, null],
            'wrong type: a number' => ['wrong type', 123, 422, 'Typ.', false, null],
            'wrong type: a list' => ['wrong type', ['tekst'], 422, 'Typ.', false, null],
        ], $executions);
    }

    /**
     * Without an accepted case there is no valid value to end with U+2028: such a row is refused, not run without it.
     */
    public function testAFieldWithAFormatNeedsAnAcceptedCase(): void
    {
        $row = TextRow::format(self::OPERATION, InputCase::STAFF, InputContractFixtures::NONE, 'postalCode', ['postalCode' => '00-950'],
            empty: 'Wymagany.', forbidden: 'Format.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', []), cases: [['00950', '00950', 422, 'Format.']]);

        $this->expectException(\LogicException::class);

        $this->executions($row);
    }

    public function testAPhraseIsA400AndItsEdgesAreOneComparisonOfTheAnswers(): void
    {
        $row = TextRow::phrase('GET /api/v1/example', InputCase::STAFF, 'product', 'namiot', empty: 'Krótka.', forbidden: 'Znaki.', phrase: '{productName}', record: '{productId}');
        $executions = $this->executions($row);

        self::assertSame([
            'empty: U+00A0' => ['empty', "\u{A0}", 400, 'Krótka.', false, null],
            'empty: U+200B' => ['empty', "\u{200B}", 400, 'Krótka.', false, null],
            'empty: U+3164' => ['empty', "\u{3164}", 400, 'Krótka.', false, null],
            'forbidden: U+0000 inside' => ['forbidden', "nam\u{0}iot", 400, 'Znaki.', false, null],
            'forbidden: U+009F inside' => ['forbidden', "nam\u{9F}iot", 400, 'Znaki.', false, null],
            'forbidden: U+2028 inside' => ['forbidden', "nam\u{2028}iot", 400, 'Znaki.', false, null],
            'forbidden: U+2028 at the end' => ['forbidden', "namiot\u{2028}", 400, 'Znaki.', false, null],
            'edges: the clean phrase, between U+200B and between U+00A0' => ['edges', '{productName}', null, null, false, null],
            'preserved: ending with the flag of England' => ['preserved', "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", null, null, false, "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"],
            'preserved: an emoji with U+200D' => ['preserved', "Kurier \u{1F469}\u{200D}\u{1F4BB}", null, null, false, "Kurier \u{1F469}\u{200D}\u{1F4BB}"],
            'preserved: Polish letters' => ['preserved', 'Zażółć gęślą jaźń', null, null, false, 'Zażółć gęślą jaźń'],
        ], $executions);
        self::assertSame(['phrase' => '{productName}', 'record' => '{productId}', 'items' => 'items', 'id' => 'id', 'count' => 'total'], $row->result);
        self::assertSame('q', $row->field);
    }

    /**
     * Two rows of one operation and field of one kind (the note of a cancellation and of a return, both required) differ
     * by the variant of the row: its name, which names each execution as a test, would be the same otherwise.
     */
    public function testRowsOfOneOperationAndFieldOfOneKindDifferByTheirVariant(): void
    {
        $note = static fn (string $variant): TextRow => TextRow::required(self::OPERATION, InputCase::STAFF, InputContractFixtures::NONE, 'note', 'Brak towaru', ['note' => null],
            empty: 'Pusta.', forbidden: 'Znaki.', wrongType: 'Typ.', stored: TextRow::column('SELECT 1', []), variant: $variant);

        self::assertSame('POST /api/v1/example, note (W)', $note('')->name());
        self::assertSame('POST /api/v1/example, note (W, return)', $note('return')->name());
        self::assertNotSame($note('cancel')->name(), $note('return')->name());
        self::assertSame(array_keys(iterator_to_array($note('')->executions())), array_keys(iterator_to_array($note('return')->executions())), 'the variant changes the name of the row, not its executions');

        $phrase = TextRow::phrase('GET /api/v1/example', InputCase::STAFF, 'product', 'namiot', empty: 'Krótka.', forbidden: 'Znaki.', phrase: '{productName}', record: '{productId}', variant: 'archive');
        self::assertSame('GET /api/v1/example, q (phrase, archive)', $phrase->name());
    }

    /**
     * An execution is a test named by its row and its own name: two rows of the table with the same name would run as
     * one, or be refused by the data provider.
     */
    public function testTheRowsOfTheTableHaveNamesOfTheirOwn(): void
    {
        $names = array_map(static fn (TextRow $row): string => $row->name(), TextRows::all());

        self::assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * The note of a status change is required for a cancellation and for a return, optional for a shipment: one row of
     * each, as the table of the spec says.
     */
    public function testTheNoteOfAStatusChangeHasARowForACancellationAShipmentAndAReturn(): void
    {
        $notes = [];
        foreach (TextRows::all() as $row) {
            if ('POST' === $row->method && '/api/v1/admin/orders/{orderId}/status-changes' === $row->path && 'note' === $row->field) {
                self::assertIsArray($row->body);
                $notes[$row->name()] = $row->body['transition'] ?? null;
            }
        }

        self::assertSame([
            'POST /api/v1/admin/orders/{orderId}/status-changes, note (O)' => 'ship',
            'POST /api/v1/admin/orders/{orderId}/status-changes, note (W)' => 'cancel',
            'POST /api/v1/admin/orders/{orderId}/status-changes, note (W, return)' => 'return',
        ], $notes);
    }

    /**
     * A row of a text of the body cannot skip the comparison of the stored value of a 2xx; only a phrase has none.
     */
    public function testEveryRowOfATextOfTheBodyReadsItsStoredValue(): void
    {
        foreach (['required', 'optional', 'format'] as $kind) {
            $stored = new \ReflectionMethod(TextRow::class, $kind)->getParameters();
            $stored = array_values(array_filter($stored, static fn (\ReflectionParameter $parameter): bool => 'stored' === $parameter->getName()));
            self::assertCount(1, $stored, $kind);
            self::assertFalse($stored[0]->allowsNull(), $kind.'() must not take a row without the read of the stored value');
            self::assertFalse($stored[0]->isOptional(), $kind);
        }

        foreach (TextRows::all() as $row) {
            if (TextRow::PHRASE !== $row->kind) {
                self::assertNotNull($row->stored, $row->name());
                foreach ($row->executions() as $name => $execution) {
                    self::assertSame(!$execution->isRefused(), $execution->readsStored, $row->name().', '.$name);
                }
            }
        }
    }

    /**
     * @return array<string, array{string, mixed, ?int, ?string, bool, mixed}>
     */
    private function executions(TextRow $row): array
    {
        $executions = [];
        foreach ($row->executions() as $name => $execution) {
            self::assertArrayNotHasKey($name, $executions, 'the names of the executions of a row are unique');
            $executions[$name] = [$execution->class, $execution->value, $execution->status, $execution->title, $execution->readsStored, $execution->stored];
        }

        return $executions;
    }
}
