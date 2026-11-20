<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of PUT /api/v1/admin/orders/{orderId}/tracking-number. Both keys are required. The values are taken as JSON
 * gives them and validated, never cast; the number follows the rules of TrackingNumber, with its messages.
 */
#[OA\Schema(description: 'The tracking number of a shipped or delivered order, based on the version of the order the staff member saw.', required: ['trackingNumber', 'version'])]
final readonly class ChangeTrackingNumberRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: 'The tracking number: 1 to 40 ASCII letters, digits and hyphens once trimmed of white space. Null or nothing but white space removes the number.', maxLength: 40, nullable: true, example: 'JJD0002-4455')]
        public mixed $trackingNumber,
        #[OA\Property(type: 'integer', description: 'The `version` of the order the change is based on.', minimum: 1, example: 4)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Wersja zamówienia jest wymagana.'),
            new Assert\Type('integer', message: 'Wersja zamówienia musi być liczbą całkowitą od 1.'),
            new Assert\Positive(message: 'Wersja zamówienia musi być liczbą całkowitą od 1.'),
        ])]
        public mixed $version,
    ) {
    }

    /**
     * The number is a text of the shape of TrackingNumber, or none; at most one violation.
     */
    #[Assert\Callback]
    public function validateTrackingNumber(ExecutionContextInterface $context): void
    {
        $violation = TrackingNumberInput::violation($this->trackingNumber);
        if (null !== $violation) {
            $context->buildViolation($violation)->atPath('trackingNumber')->addViolation();
        }
    }
}
