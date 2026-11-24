<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Inventory\Domain\Stock\StockNote;
use App\Inventory\Domain\Stock\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StockMovement::isReceiptOf: a receipt sent again with its key is the same receipt, also when the note was stored
 * before the rule of texts trimmed the ends of a note of every Unicode white space and invisible character.
 */
final class StockMovementReceiptTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    /**
     * @return iterable<string, array{?string, ?string, bool}> the note as stored, the note sent again, whether they are the same receipt
     */
    public static function notes(): iterable
    {
        yield 'a note stored as it is' => ['Dostawa', 'Dostawa', true];
        yield 'a note stored with no-break spaces at its ends, sent again as it was' => ["\u{A0}Dostawa\u{A0}", "\u{A0}Dostawa\u{A0}", true];
        yield 'a note stored with a zero width space at its end, sent again clean' => ["Dostawa\u{200B}", 'Dostawa', true];
        yield 'a note stored of an ideographic space, sent again as no note' => ["\u{3000}", null, true];
        yield 'no note stored, none sent' => [null, null, true];
        yield 'no note stored, one sent' => [null, 'Dostawa', false];
        yield 'a note stored, none sent' => ['Dostawa', null, false];
        yield 'another note' => ["\u{A0}Dostawa\u{A0}", 'Inna', false];
        yield 'the inside differs' => ["Dostawa\u{A0}paleta", 'Dostawa paleta', false];
    }

    #[DataProvider('notes')]
    public function testTheStoredNoteIsComparedByTheRuleOfTexts(?string $stored, ?string $sent, bool $same): void
    {
        $variantId = VariantId::fromString(self::ID);
        $receipt = new StockMovement($variantId, StockMovementType::Receipt, 12, 0, 12, 0, null, $stored, 'delivery-1', StockChangeOrigin::staff('sub-1', 'Wanda'), new \DateTimeImmutable('2026-11-24T10:00:00Z'));

        self::assertSame($same, $receipt->isReceiptOf($variantId, 12, StockNote::fromNullable($sent)));
    }
}
