<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Domain\Cart;
use App\Cart\Domain\Quantity;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PUT …/items/{productId}: the new quantity (absolute), required, an integer as JSON gives it.
 */
#[OA\Schema(description: 'The new quantity of a line.', required: ['quantity'])]
final readonly class ChangeCartItemQuantityRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'The quantity, from 1 to 99.', minimum: Quantity::MIN, maximum: Cart::MAX_QUANTITY, example: 3)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(notInRangeMessage: 'Ilość musi wynosić od 1 do 99 szt.', min: Quantity::MIN, max: Cart::MAX_QUANTITY)]
        public mixed $quantity,
    ) {
    }
}
