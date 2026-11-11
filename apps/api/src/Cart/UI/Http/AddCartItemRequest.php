<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Domain\Cart;
use App\Cart\Domain\Quantity;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST …/items. Both keys are required: the serializer gives `null` for a missing key (the properties are
 * `mixed`), and `Assert\NotNull` refuses it, like an explicit `null`. The values are taken as JSON gives them and
 * validated, never cast: a number in a string or with a fraction (also `2.0`) is a violation. The constraints of a
 * field run in order up to the first violation, so a field gives at most one, in Polish. `Assert\Uuid` alone reports
 * several violations for one value (nil, max, a wrong version and variant, an overlong text), so it sits in
 * `Assert\AtLeastOneOf` without internal messages, which reports the one message of the field. `Assert\Uuid` skips an
 * empty string, so `Assert\NotBlank` (with the UUID message, not "required") refuses it first.
 */
#[OA\Schema(description: 'A variant of a product to add to the cart.', required: ['variantId', 'quantity'])]
final readonly class AddCartItemRequest
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid', description: 'Variant id (UUID) of a product.')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Identyfikator wariantu jest wymagany.'),
            new Assert\Type('string', message: 'Identyfikator wariantu musi być UUID.'),
            new Assert\NotBlank(message: 'Identyfikator wariantu musi być UUID.'),
            new Assert\AtLeastOneOf(
                constraints: [new Assert\Uuid()],
                message: 'Identyfikator wariantu musi być UUID.',
                includeInternalMessages: false,
            ),
        ])]
        public mixed $variantId,
        #[OA\Property(type: 'integer', description: 'Units to add, from 1 to 99. The line of the variant may have at most 99 units.', minimum: Quantity::MIN, maximum: Cart::MAX_QUANTITY, example: 1)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Ilość jest wymagana.'),
            new Assert\Type('integer', message: 'Ilość musi być liczbą całkowitą.'),
            new Assert\Range(notInRangeMessage: 'Ilość musi wynosić od 1 do 99 szt.', min: Quantity::MIN, max: Cart::MAX_QUANTITY),
        ])]
        public mixed $quantity,
    ) {
    }
}
