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
    }

    public function testAFieldWithAFormatRunsItsCasesInPlaceOfTheClassesOfText(): void
    {
        $executions = $this->executions(TextRow::format(self::OPERATION, InputCase::STAFF, InputContractFixtures::NONE, 'address.postalCode', ['address' => ['postalCode' => '00-950']],
            empty: 'Wymagany.', wrongType: 'Format.', stored: TextRow::column('SELECT 1', []), cases: [
                ['00-950 between U+00A0', "\u{A0}00-950\u{A0}", 201, '00-950'],
                ['00950', '00950', 422, 'Format.'],
            ]));

        self::assertSame([
            'empty: U+00A0' => ['empty', "\u{A0}", 422, 'Wymagany.', false, null],
            'empty: U+200B' => ['empty', "\u{200B}", 422, 'Wymagany.', false, null],
            'empty: U+3164' => ['empty', "\u{3164}", 422, 'Wymagany.', false, null],
            'format: 00-950 between U+00A0' => ['format', "\u{A0}00-950\u{A0}", 201, null, true, '00-950'],
            'format: 00950' => ['format', '00950', 422, 'Format.', false, null],
            'wrong type: a number' => ['wrong type', 123, 422, 'Format.', false, null],
            'wrong type: a list' => ['wrong type', ['tekst'], 422, 'Format.', false, null],
        ], $executions);
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
            'edges: the clean phrase, between U+200B and between U+00A0' => ['edges', '{productName}', null, null, false, null],
            'preserved: ending with the flag of England' => ['preserved', "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", null, null, false, "Kurier \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"],
            'preserved: an emoji with U+200D' => ['preserved', "Kurier \u{1F469}\u{200D}\u{1F4BB}", null, null, false, "Kurier \u{1F469}\u{200D}\u{1F4BB}"],
            'preserved: Polish letters' => ['preserved', 'Zażółć gęślą jaźń', null, null, false, 'Zażółć gęślą jaźń'],
        ], $executions);
        self::assertSame(['phrase' => '{productName}', 'record' => '{productId}', 'items' => 'items', 'id' => 'id', 'count' => 'total'], $row->result);
        self::assertSame('q', $row->field);
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
