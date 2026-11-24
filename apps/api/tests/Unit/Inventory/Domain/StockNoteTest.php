<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\StockNote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockNoteTest extends TestCase
{
    public function testANoteIsTrimmed(): void
    {
        $note = StockNote::fromNullable("  Dostawa od Biegam sp. z o.o.\n");

        self::assertSame('Dostawa od Biegam sp. z o.o.', $note->toNullableString());
        self::assertFalse($note->isEmpty());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function noNotes(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'only white space' => [" \t\n "];
        yield 'only no-break spaces' => ["\u{A0}\u{A0}"];
        yield 'only a zero width space' => ["\u{200B}"];
        yield 'only a hangul filler, which is not seen' => ["\u{3164}"];
        yield 'only a joiner and a mark of direction' => ["\u{200D}\u{200E}"];
    }

    #[DataProvider('noNotes')]
    public function testNothingButWhiteSpaceIsNoNote(?string $value): void
    {
        $note = StockNote::fromNullable($value);

        self::assertNull($note->toNullableString());
        self::assertTrue($note->isEmpty());
        self::assertTrue($note->equals(StockNote::none()));
    }

    public function testFiveHundredCharactersAfterTrimmingAreAccepted(): void
    {
        $text = str_repeat('ż', 500);

        self::assertSame($text, StockNote::fromNullable('  '.$text.'  ')->toNullableString());
    }

    public function testMoreThanFiveHundredCharactersAreInvalidInput(): void
    {
        try {
            StockNote::fromNullable(str_repeat('ż', 501));
            self::fail('Expected InvalidStockNote.');
        } catch (InvalidStockNote $invalid) {
            self::assertSame('A note may have at most 500 characters.', $invalid->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notesWithControlCharacters(): iterable
    {
        yield 'NUL inside' => ["a\0b"];
        yield 'NUL at the end, which trimming would drop' => ["a\0"];
        yield 'NUL alone' => ["\0"];
        yield 'a vertical tab, which trimming would drop' => ["\x0Ba"];
        yield 'escape' => ["a\x1Bb"];
        yield 'unit separator' => ["a\x1Fb"];
        yield 'delete' => ["a\x7Fb"];
        yield 'next line (C1)' => ["a\u{85}b"];
        yield 'application program command (C1)' => ["a\u{9F}b"];
        yield 'C1 at the end, which trimming would drop' => ["a\u{85}"];
        yield 'line separator inside' => ["a\u{2028}b"];
        yield 'line separator at the end, which trimming would drop' => ["a\u{2028}"];
        yield 'paragraph separator inside' => ["a\u{2029}b"];
        yield 'paragraph separator at the end, which trimming would drop' => ["a\u{2029}"];
    }

    #[DataProvider('notesWithControlCharacters')]
    public function testAControlCharacterOtherThanTabOrLineBreakIsInvalidInput(string $text): void
    {
        try {
            StockNote::fromNullable($text);
            self::fail('Expected InvalidStockNote.');
        } catch (InvalidStockNote $invalid) {
            self::assertSame('A note may not contain control characters other than tabs and line breaks.', $invalid->getMessage());
        }
    }

    public function testTabsAndLineBreaksInsideANoteAreKept(): void
    {
        self::assertSame("Dostawa\r\n\tpaleta 2", StockNote::fromNullable("Dostawa\r\n\tpaleta 2")->toNullableString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notesBetweenEdges(): iterable
    {
        yield 'no-break spaces' => ["\u{A0}Dostawa\u{A0}", 'Dostawa'];
        yield 'zero width spaces' => ["\u{200B}Dostawa\u{200B}", 'Dostawa'];
        yield 'a byte order mark and a soft hyphen' => ["\u{FEFF}Dostawa\u{AD}", 'Dostawa'];
        yield 'ideographic spaces' => ["\u{3000}Dostawa\u{3000}", 'Dostawa'];
        yield 'tabs and line breaks, which are white space' => ["\t\r\nDostawa\r\n\t", 'Dostawa'];
        yield 'the ends only, the inside stays' => ["\u{A0}Dostawa\u{A0}paleta\u{A0}", "Dostawa\u{A0}paleta"];
    }

    #[DataProvider('notesBetweenEdges')]
    public function testTheEndsLoseUnicodeWhiteSpaceAndInvisibleCharacters(string $text, string $expected): void
    {
        self::assertSame($expected, StockNote::fromNullable($text)->toNullableString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notesToKeep(): iterable
    {
        yield 'the flag of England at the end' => ["Paleta \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"];
        yield 'an emoji with a joiner' => ["Paleta \u{1F469}\u{200D}\u{1F4BB}"];
        yield 'Polish letters' => ['Zażółć gęślą jaźń'];
        yield 'a tab and line breaks inside' => ["Dostawa\tpaleta\r\n2\npaleta 3"];
    }

    #[DataProvider('notesToKeep')]
    public function testANoteWithVisibleTextAndFormatCharactersInsideIsKeptAsItIs(string $text): void
    {
        self::assertSame($text, StockNote::fromNullable($text)->toNullableString());
    }

    public function testTheLengthIsCountedAfterTrimmingTheEnds(): void
    {
        $text = str_repeat('ż', 500);

        self::assertSame($text, StockNote::fromNullable("\u{A0}\u{200B}".$text."\u{3000}")->toNullableString());
        $this->expectException(InvalidStockNote::class);
        StockNote::fromNullable("\u{A0}".$text.'ż');
    }

    public function testNotesAreEqualByTheirTrimmedText(): void
    {
        self::assertTrue(StockNote::fromNullable(' a ')->equals(StockNote::fromNullable('a')));
        self::assertFalse(StockNote::fromNullable('a')->equals(StockNote::fromNullable('b')));
        self::assertFalse(StockNote::fromNullable('a')->equals(StockNote::none()));
    }
}
