<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockNote;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/v1/admin/products/{productId}/stock/receipts. Both keys are required. The values are taken as JSON
 * gives them and validated, never cast: a number in a string or with a fraction (also `5.0`) is a violation. The
 * messages of the ranges are shown to a staff member at the field, so they are in Polish. The actor is never part of
 * the body, it comes from the token.
 */
#[OA\Schema(description: 'Delivered units of a product.', required: ['quantity', 'note'])]
final readonly class ReceiveStockRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'Units received, from 1 to 100 000. The physical stock may not exceed 1 000 000.', minimum: 1, maximum: StockItem::MAX_RECEIPT, example: 24)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(notInRangeMessage: 'Ilość musi wynosić od 1 do 100 000 szt.', min: 1, max: StockItem::MAX_RECEIPT)]
        public mixed $quantity,
        #[OA\Property(type: 'string', description: 'A note, at most 500 characters once trimmed, without control characters other than tab and line breaks; null or blank for none.', maxLength: StockNote::MAX_LENGTH, nullable: true, example: 'Dostawa od Biegam sp. z o.o.')]
        #[Assert\Type('string')]
        #[Assert\Length(max: StockNote::MAX_LENGTH, maxMessage: 'Notatka może mieć najwyżej 500 znaków.', normalizer: 'trim')]
        #[Assert\Regex(pattern: StockNote::CONTROL_CHARACTERS, match: false, htmlPattern: '^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$', message: 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).')]
        public mixed $note,
    ) {
    }
}
