<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the contract of texts of the operations of Inventory (TextRow): the note of a correction of the stock and of
 * a receipt, a text of lines (a tab, a line feed and a carriage return are allowed), and the phrase of the list of
 * stocks.
 */
final class InventoryTextRows
{
    private const string CORRECT = 'POST /api/v1/admin/variants/{variantId}/stock/corrections';
    private const string RECEIVE = 'POST /api/v1/admin/variants/{variantId}/stock/receipts';
    private const string NOTE = 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).';
    private const string NOTE_TYPE = 'Notatka musi być tekstem.';
    private const string NOTE_OF_OTHER = 'Przy powodzie „inne” notatka jest wymagana.';
    /** The tab, the line feed and the carriage return of T1 a note allows. */
    private const string ALLOWED = "\t\n\r";
    /** The note a staff member wrote, not the seed receipt of the fixture. */
    private const string CORRECTION_NOTE = "SELECT note FROM inventory_stock_movement WHERE variant_id = :variantId AND type = 'correction'";
    private const string RECEIPT_NOTE = "SELECT note FROM inventory_stock_movement WHERE variant_id = :variantId AND type = 'receipt' AND source = 'staff'";

    /**
     * @return iterable<TextRow>
     */
    public static function all(): iterable
    {
        $staff = InputCase::STAFF;
        $variant = ['variantId' => '{variantId}'];

        // The fixture `variant` has a stock of 5 units at version 1.
        $correction = ['physical' => 7, 'version' => 1, 'reason' => 'damage', 'note' => 'Inwentaryzacja roczna'];

        yield TextRow::optional(self::CORRECT, $staff, 'variant', 'note', 'Inwentaryzacja roczna', $correction,
            noValue: null,
            forbidden: self::NOTE,
            wrongType: self::NOTE_TYPE,
            stored: TextRow::column(self::CORRECTION_NOTE, $variant),
            allowed: self::ALLOWED);
        yield TextRow::required(self::CORRECT, $staff, 'variant', 'note', 'Inwentaryzacja roczna', [...$correction, 'reason' => 'other'],
            empty: self::NOTE_OF_OTHER,
            forbidden: self::NOTE,
            wrongType: self::NOTE_TYPE,
            stored: TextRow::column(self::CORRECTION_NOTE, $variant),
            allowed: self::ALLOWED);
        yield TextRow::optional(self::RECEIVE, $staff, 'variant', 'note', 'Dostawa od Biegam sp. z o.o.', ['quantity' => 5, 'note' => 'Dostawa od Biegam sp. z o.o.'],
            noValue: null,
            forbidden: self::NOTE,
            wrongType: self::NOTE_TYPE,
            stored: TextRow::column(self::RECEIPT_NOTE, $variant),
            allowed: self::ALLOWED,
            headers: ['Idempotency-Key' => '{idempotencyKey}']);

        yield from self::phrases();
    }

    /**
     * @return iterable<TextRow>
     */
    private static function phrases(): iterable
    {
        yield TextRow::phrase('GET /api/v1/admin/stock', InputCase::STAFF, 'indexedProduct', 'namiot', ValidatorRows::tooShort(2), 'Szukana fraza zawiera niedozwolone znaki.',
            phrase: '{productName}', record: '{productId}', items: 'items', id: 'productId', count: 'total');
    }
}
