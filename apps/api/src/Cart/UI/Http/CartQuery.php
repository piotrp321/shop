<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\Query\GetCart;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of the cart read: the discount code of the preview. Taken as the query gives it (a string, or an array
 * for `discountCode[]=`), so a value of a wrong type is a violation with a Polish message rather than the framework's
 * English text. A blank code is no code; Pricing judges the rest. The messages never contain the submitted value.
 */
final readonly class CartQuery
{
    private const string TYPE_MESSAGE = 'Kod rabatowy musi być tekstem.';
    private const string LENGTH_MESSAGE = 'Kod rabatowy może mieć najwyżej 64 znaki.';
    private const string CHARSET_MESSAGE = 'Kod rabatowy zawiera niedozwolone znaki.';

    public function __construct(
        #[OA\Property(type: 'string', nullable: true)]
        #[Assert\Sequentially([
            new Assert\Type('string', message: self::TYPE_MESSAGE),
            new Assert\Length(max: GetCart::MAX_DISCOUNT_CODE_LENGTH, maxMessage: self::LENGTH_MESSAGE, charsetMessage: self::CHARSET_MESSAGE),
        ])]
        public mixed $discountCode = null,
    ) {
    }

    // Not named after the parameter: the serializer would take the type of a method named so.
    public function code(): ?string
    {
        return \is_string($this->discountCode) ? $this->discountCode : null;
    }
}
