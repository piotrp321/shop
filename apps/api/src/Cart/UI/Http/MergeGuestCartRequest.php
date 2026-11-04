<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /me/cart/merge. The key is required by `Assert\NotNull`, not by the serializer: it gives `null` for
 * a missing key (the property is `mixed`). The value is taken as JSON gives it and validated, never cast. The
 * constraints run in order up to the first violation, so the field gives at most one, in Polish.
 */
#[OA\Schema(description: 'The guest cart to merge into the cart of the customer.', required: ['guestCartId'])]
final readonly class MergeGuestCartRequest
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid', description: 'Id of the guest cart (UUID).')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Identyfikator koszyka jest wymagany.'),
            new Assert\Type('string', message: 'Identyfikator koszyka musi być UUID.'),
            new Assert\Uuid(message: 'Identyfikator koszyka musi być UUID.'),
        ])]
        public mixed $guestCartId,
    ) {
    }
}
