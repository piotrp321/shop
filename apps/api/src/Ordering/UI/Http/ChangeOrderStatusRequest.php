<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Domain\ControlCharacters;
use App\Ordering\Domain\StaffNote;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of POST /api/v1/admin/orders/{orderId}/status-changes. All keys are required. The values are taken as JSON
 * gives them and validated, never cast; the rules of the note are the ones of StaffNote, with its messages, so a
 * staff member sees them at the field. The staff member is never part of the body, it comes from the token.
 */
#[OA\Schema(description: 'A change of the status of an order, based on the version of the order the staff member saw.', required: ['transition', 'version', 'note'])]
final readonly class ChangeOrderStatusRequest
{
    /** The values of StaffTransition. */
    public const array TRANSITIONS = ['ship', 'deliver', 'cancel', 'return'];
    /** The transitions that need a note (P7). */
    private const array NOTE_REQUIRED = ['cancel', 'return'];

    public function __construct(
        #[OA\Property(type: 'string', description: '`ship` (paid → shipped), `deliver` (shipped → delivered), `cancel` (paid → cancelled, a refund to do), `return` (shipped or delivered → returned, a refund to do).', enum: self::TRANSITIONS, example: 'ship')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Wybierz zmianę statusu.'),
            new Assert\Type('string', message: 'Wybierz zmianę statusu: wysłane, dostarczone, anulowanie albo zwrot.'),
            new Assert\Choice(choices: self::TRANSITIONS, message: 'Wybierz zmianę statusu: wysłane, dostarczone, anulowanie albo zwrot.'),
        ])]
        public mixed $transition,
        #[OA\Property(type: 'integer', description: 'The `version` of the order the change is based on.', minimum: 1, example: 2)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Wersja zamówienia jest wymagana.'),
            new Assert\Type('integer', message: 'Wersja zamówienia musi być liczbą całkowitą od 1.'),
            new Assert\Positive(message: 'Wersja zamówienia musi być liczbą całkowitą od 1.'),
        ])]
        public mixed $version,
        #[OA\Property(type: 'string', description: 'One line, at most 500 characters once trimmed, without control characters; required for `cancel` and `return`, otherwise null or blank for none.', maxLength: StaffNote::MAX_LENGTH, nullable: true, example: 'Kurier DPD')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: 'Notatka musi być tekstem.'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Notatka zawiera niedozwolone znaki.'),
            new Assert\Length(max: StaffNote::MAX_LENGTH, maxMessage: 'Notatka może mieć najwyżej 500 znaków.', normalizer: 'trim'),
        ])]
        public mixed $note,
    ) {
    }

    /**
     * A cancellation and a return are explained in the note; the domain refuses them without one too, this puts the
     * message at the field before the role and the order are checked.
     */
    #[Assert\Callback]
    public function validateRequiredNote(ExecutionContextInterface $context): void
    {
        if (\in_array($this->transition, self::NOTE_REQUIRED, true) && (null === $this->note || (\is_string($this->note) && '' === trim($this->note)))) {
            $context->buildViolation('Notatka jest wymagana.')->atPath('note')->addViolation();
        }
    }
}
