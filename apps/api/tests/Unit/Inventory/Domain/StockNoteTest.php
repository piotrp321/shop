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

    public function testNotesAreEqualByTheirTrimmedText(): void
    {
        self::assertTrue(StockNote::fromNullable(' a ')->equals(StockNote::fromNullable('a')));
        self::assertFalse(StockNote::fromNullable('a')->equals(StockNote::fromNullable('b')));
        self::assertFalse(StockNote::fromNullable('a')->equals(StockNote::none()));
    }
}
