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

    public function testNotesAreEqualByTheirTrimmedText(): void
    {
        self::assertTrue(StockNote::fromNullable(' a ')->equals(StockNote::fromNullable('a')));
        self::assertFalse(StockNote::fromNullable('a')->equals(StockNote::fromNullable('b')));
        self::assertFalse(StockNote::fromNullable('a')->equals(StockNote::none()));
    }
}
