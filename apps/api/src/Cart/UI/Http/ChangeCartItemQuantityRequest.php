<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Domain\Cart;
use App\Cart\Domain\Quantity;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PUT …/items/{productId}: the new quantity (absolute), required, an integer as JSON gives it. The key is
 * required by `Assert\NotNull` (the serializer gives `null` for a missing one, the property is `mixed`), not by the
 * serializer. The constraints run in order up to the first violation, so the field gives at most one, in Polish.
 */
#[OA\Schema(description: 'The new quantity of a line.', required: ['quantity'])]
final readonly class ChangeCartItemQuantityRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'The quantity, from 1 to 99.', minimum: Quantity::MIN, maximum: Cart::MAX_QUANTITY, example: 3)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Ilość jest wymagana.'),
            new Assert\Type('integer', message: 'Ilość musi być liczbą całkowitą.'),
            new Assert\Range(notInRangeMessage: 'Ilość musi wynosić od 1 do 99 szt.', min: Quantity::MIN, max: Cart::MAX_QUANTITY),
        ])]
        public mixed $quantity,
    ) {
    }
}
