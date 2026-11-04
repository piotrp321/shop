<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Stock\StockChangeSource;

/**
 * Puts delivered units of a variant on the shelf. The receipt key makes the command idempotent: sending it again
 * with the same variant, quantity and note changes nothing and answers with the stock as it is now. A staff receipt
 * names the staff member, a demo data receipt has no actor.
 */
final readonly class ReceiveStock
{
    /**
     * @param string $variantId  the variant UUID
     * @param int    $quantity   1 to 100 000 units
     * @param string $receiptKey 1 to 100 characters [A-Za-z0-9._:-]
     */
    private function __construct(
        public string $variantId,
        public int $quantity,
        public string $receiptKey,
        public ?string $note,
        public StockChangeSource $source,
        public ?string $actorId,
        public ?string $actorName,
    ) {
    }

    /**
     * @param string $receiptKey the `Idempotency-Key` of the request
     * @param string $actorId    the `sub` of the backoffice token
     * @param string $actorName  the name of the staff member from the token
     */
    public static function byStaff(string $variantId, int $quantity, string $receiptKey, ?string $note, string $actorId, string $actorName): self
    {
        return new self($variantId, $quantity, $receiptKey, $note, StockChangeSource::Staff, $actorId, $actorName);
    }

    /**
     * The demo data, without a note.
     */
    public static function bySeed(string $variantId, int $quantity, string $receiptKey): self
    {
        return new self($variantId, $quantity, $receiptKey, null, StockChangeSource::Seed, null, null);
    }
}
