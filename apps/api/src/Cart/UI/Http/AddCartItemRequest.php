<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Domain\Cart;
use App\Cart\Domain\Quantity;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST …/items. Both keys are required; the values are taken as JSON gives them and validated, never cast:
 * a number in a string or with a fraction (also `2.0`) is a violation.
 */
#[OA\Schema(description: 'A product to add to the cart.', required: ['productId', 'quantity'])]
final readonly class AddCartItemRequest
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid', description: 'Product id (UUID).')]
        #[Assert\NotNull]
        #[Assert\Type('string')]
        #[Assert\Uuid(message: 'Identyfikator produktu musi być UUID.')]
        public mixed $productId,
        #[OA\Property(type: 'integer', description: 'Units to add, from 1 to 99. The line may have at most 99 units.', minimum: Quantity::MIN, maximum: Cart::MAX_QUANTITY, example: 1)]
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Range(notInRangeMessage: 'Ilość musi wynosić od 1 do 99 szt.', min: Quantity::MIN, max: Cart::MAX_QUANTITY)]
        public mixed $quantity,
    ) {
    }
}
