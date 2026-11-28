<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockNote;
use App\Shared\Domain\Text;
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
    private const string REASON_MESSAGE = 'Wybierz powód korekty: inwentaryzacja, uszkodzenie, zaginięcie albo inne.';

    public function __construct(
        #[OA\Property(type: 'integer', description: 'Units counted on the shelf, from 0 to 1 000 000; not less than is reserved.', minimum: 0, maximum: StockItem::MAX_PHYSICAL, example: 18)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Stan policzony jest wymagany.'),
            new Assert\Type('integer', message: 'Stan policzony musi być liczbą całkowitą.'),
            new Assert\Range(notInRangeMessage: 'Stan policzony musi wynosić od 0 do 1 000 000 szt.', min: 0, max: StockItem::MAX_PHYSICAL),
        ])]
        public mixed $physical,
        #[OA\Property(type: 'integer', description: 'The `version` of the stock the correction is based on; null when the variant has no stock record.', nullable: true, example: 3)]
        #[Assert\Type('integer', message: 'Wersja stanu musi być liczbą całkowitą albo null.')]
        public mixed $version,
        #[OA\Property(type: 'string', description: 'Why: inventory count, damage, loss or another reason (explained in the note).', enum: ['inventory_count', 'damage', 'loss', 'other'], example: 'inventory_count')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: self::REASON_MESSAGE),
            new Assert\Type('string', message: self::REASON_MESSAGE),
            new Assert\Choice(choices: ['inventory_count', 'damage', 'loss', 'other'], message: self::REASON_MESSAGE),
        ])]
        public mixed $reason,
        #[OA\Property(type: 'string', description: 'A note, at most 500 characters once trimmed of white space and invisible characters at the ends, without control characters other than tab and line breaks; required for the reason `other`, otherwise null or a text without a visible character for none.', maxLength: StockNote::MAX_LENGTH, nullable: true, example: 'Inwentaryzacja roczna')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: 'Notatka musi być tekstem.'),
            new Assert\Callback([StockNoteRules::class, 'validateCharacters']),
            new Assert\Length(max: StockNote::MAX_LENGTH, maxMessage: 'Notatka może mieć najwyżej 500 znaków.', normalizer: [Text::class, 'normalize']),
        ])]
        public mixed $note,
    ) {
    }

    /**
     * Another reason is explained in the note; the domain refuses it too, this puts the message at the field. A text
     * without a visible character (the rule of texts) is no note; one with a forbidden character has its own violation.
     */
    #[Assert\Callback]
    public function validateNoteOfAnotherReason(ExecutionContextInterface $context): void
    {
        if ('other' === $this->reason && (null === $this->note || StockNoteRules::isBlank($this->note))) {
            $context->buildViolation('Przy powodzie „inne” notatka jest wymagana.')->atPath('note')->addViolation();
        }
    }
}
