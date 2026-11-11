<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Domain\ControlCharacters;
use App\Ordering\Domain\StaffNote;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/v1/admin/orders/{orderId}/refund-completion. All keys are required. The values are taken as JSON
 * gives them and validated, never cast; the note follows the rules of StaffNote, with its messages. The staff member
 * is never part of the body, it comes from the token.
 */
#[OA\Schema(description: 'The refund of an order marked made, based on the version of the order the staff member saw.', required: ['version', 'note'])]
final readonly class CompleteRefundRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'The `version` of the order the completion is based on.', minimum: 1, example: 3)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Wersja zamówienia jest wymagana.'),
            new Assert\Type('integer', message: 'Wersja zamówienia musi być liczbą całkowitą od 1.'),
            new Assert\Positive(message: 'Wersja zamówienia musi być liczbą całkowitą od 1.'),
        ])]
        public mixed $version,
        #[OA\Property(type: 'string', description: 'One line, at most 500 characters once trimmed of white space, without control characters, U+0085, U+2028 and U+2029; null or blank for none.', maxLength: StaffNote::MAX_LENGTH, nullable: true, example: 'Przelew wysłany')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: 'Notatka musi być tekstem.'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Notatka zawiera niedozwolone znaki.'),
            new Assert\Length(max: StaffNote::MAX_LENGTH, maxMessage: 'Notatka może mieć najwyżej 500 znaków.', charsetMessage: 'Notatka zawiera niedozwolone znaki.', normalizer: StaffNote::class.'::trim'),
        ])]
        public mixed $note,
    ) {
    }
}
