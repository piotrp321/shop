<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockNote;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of POST /api/v1/admin/variants/{variantId}/stock/corrections. All keys are required. The values are taken as
 * JSON gives them and validated, never cast. The messages a staff member sees at a field are in Polish. The actor is
 * never part of the body, it comes from the token.
 */
#[OA\Schema(description: 'The counted physical stock of a product variant, based on the version of the stock the staff member saw.', required: ['physical', 'version', 'reason', 'note'])]
final readonly class CorrectStockRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'Units counted on the shelf, from 0 to 1 000 000; not less than is reserved.', minimum: 0, maximum: StockItem::MAX_PHYSICAL, example: 18)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(notInRangeMessage: 'Stan policzony musi wynosić od 0 do 1 000 000 szt.', min: 0, max: StockItem::MAX_PHYSICAL)]
        public mixed $physical,
        #[OA\Property(type: 'integer', description: 'The `version` of the stock the correction is based on; null when the variant has no stock record.', nullable: true, example: 3)]
        #[Assert\Type('integer')]
        public mixed $version,
        #[OA\Property(type: 'string', description: 'Why: inventory count, damage, loss or another reason (explained in the note).', enum: ['inventory_count', 'damage', 'loss', 'other'], example: 'inventory_count')]
        #[Assert\NotNull]
        #[Assert\Type('string')]
        #[Assert\Choice(choices: ['inventory_count', 'damage', 'loss', 'other'], message: 'Wybierz powód korekty: inwentaryzacja, uszkodzenie, zaginięcie albo inne.')]
        public mixed $reason,
        #[OA\Property(type: 'string', description: 'A note, at most 500 characters once trimmed, without control characters other than tab and line breaks; required for the reason `other`, otherwise null or blank for none.', maxLength: StockNote::MAX_LENGTH, nullable: true, example: 'Inwentaryzacja roczna')]
        #[Assert\Type('string')]
        #[Assert\Length(max: StockNote::MAX_LENGTH, maxMessage: 'Notatka może mieć najwyżej 500 znaków.', normalizer: 'trim')]
        #[Assert\Regex(pattern: StockNote::CONTROL_CHARACTERS, match: false, htmlPattern: '^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$', message: 'Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).')]
        public mixed $note,
    ) {
    }

    /**
     * Another reason is explained in the note; the domain refuses it too, this puts the message at the field.
     */
    #[Assert\Callback]
    public function validateNoteOfAnotherReason(ExecutionContextInterface $context): void
    {
        if ('other' === $this->reason && (null === $this->note || (\is_string($this->note) && '' === trim($this->note)))) {
            $context->buildViolation('Przy powodzie „inne” notatka jest wymagana.')->atPath('note')->addViolation();
        }
    }
}
