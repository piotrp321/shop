<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /me/cart/merge. The key is required; the value is taken as JSON gives it and validated, never cast.
 */
#[OA\Schema(description: 'The guest cart to merge into the cart of the customer.', required: ['guestCartId'])]
final readonly class MergeGuestCartRequest
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid', description: 'Id of the guest cart (UUID).')]
        #[Assert\NotNull]
        #[Assert\Type('string')]
        #[Assert\Uuid(message: 'Identyfikator koszyka musi być UUID.')]
        public mixed $guestCartId,
    ) {
    }
}
