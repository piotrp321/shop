<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\DiscountCodeText;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\UI\Http\Validation\ExpectedLinesFormat;
use App\Ordering\UI\Http\Validation\TextCharacters;
use App\Shared\Domain\Text;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/v1/me/orders. The email is the one of the token, so the body has none (an `email` key is ignored,
 * like any unknown key: the lines, quantities and prices of the order never come from the body, `expectedLines` is only
 * compared with the cart). Every key but `discountCode` is required: the
 * serializer gives `null` for a missing key (the properties are `mixed`). The values are taken as JSON gives them and
 * validated, never cast. The constraints of a field run in order up to the first violation, so a field gives at most
 * one, in Polish; the domain checks the same rules again. The rules are the same as in PlaceGuestOrderRequest.
 */
#[OA\Schema(description: 'The contact and the address of an order of the cart of the customer, the total and the lines the customer saw, the delivery method chosen and the discount code applied.', required: ['fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross', 'deliveryMethodId', 'expectedLines'])]
final readonly class PlaceCustomerOrderRequest
{
    public function __construct(
        #[OA\Property(type: 'string', maxLength: Contact::MAX_FULL_NAME_LENGTH, description: 'Full name, 1 to 100 characters once trimmed of white space and invisible characters at the ends, with a visible character, without control characters or line separators.', example: 'Jan Kowalski')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Imię i nazwisko są wymagane.'),
            new Assert\Type('string', message: 'Imię i nazwisko są wymagane.'),
            new Assert\NotBlank(message: 'Imię i nazwisko są wymagane.', normalizer: [Text::class, 'normalize']),
            new Assert\Length(max: Contact::MAX_FULL_NAME_LENGTH, maxMessage: 'Imię i nazwisko mogą mieć najwyżej 100 znaków.', charsetMessage: 'Imię i nazwisko zawierają niedozwolone znaki.', normalizer: [Text::class, 'normalize']),
            new TextCharacters(message: 'Imię i nazwisko zawierają niedozwolone znaki.'),
        ])]
        public mixed $fullName,
        #[OA\Property(type: 'string', description: 'A Polish phone number: 9 digits, optionally after +48; spaces and hyphens are allowed and dropped, white space and invisible characters at the ends are trimmed, control characters and line separators are refused. The order keeps +48 and the 9 digits.', example: '600 100 200')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Numer telefonu jest wymagany.'),
            new Assert\Type('string', message: 'Podaj 9-cyfrowy numer telefonu.'),
            new TextCharacters(message: 'Podaj 9-cyfrowy numer telefonu.'),
            new Assert\NotBlank(message: 'Numer telefonu jest wymagany.', normalizer: [Text::class, 'normalize']),
            new Assert\Regex(pattern: PlaceGuestOrderRequest::PHONE_PATTERN, message: 'Podaj 9-cyfrowy numer telefonu.', normalizer: [Text::class, 'normalize']),
        ])]
        public mixed $phone,
        #[OA\Property(type: 'string', maxLength: ShippingAddress::MAX_STREET_LENGTH, description: 'Street and number, 1 to 100 characters once trimmed of white space and invisible characters at the ends, with a visible character, without control characters or line separators.', example: 'ul. Długa 5/3')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Ulica i numer są wymagane.'),
            new Assert\Type('string', message: 'Ulica i numer są wymagane.'),
            new Assert\NotBlank(message: 'Ulica i numer są wymagane.', normalizer: [Text::class, 'normalize']),
            new Assert\Length(max: ShippingAddress::MAX_STREET_LENGTH, maxMessage: 'Ulica i numer mogą mieć najwyżej 100 znaków.', charsetMessage: 'Ulica i numer zawierają niedozwolone znaki.', normalizer: [Text::class, 'normalize']),
            new TextCharacters(message: 'Ulica i numer zawierają niedozwolone znaki.'),
        ])]
        public mixed $street,
        #[OA\Property(type: 'string', description: 'Polish postal code NN-NNN, without control characters or line separators; white space and invisible characters at the ends are trimmed.', example: '00-950')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kod pocztowy jest wymagany.'),
            new Assert\Type('string', message: 'Podaj kod pocztowy w formacie 00-000.'),
            new TextCharacters(message: 'Podaj kod pocztowy w formacie 00-000.'),
            new Assert\NotBlank(message: 'Kod pocztowy jest wymagany.', normalizer: [Text::class, 'normalize']),
            new Assert\Regex(pattern: PlaceGuestOrderRequest::POSTAL_CODE_PATTERN, message: 'Podaj kod pocztowy w formacie 00-000.', normalizer: [Text::class, 'normalize']),
        ])]
        public mixed $postalCode,
        #[OA\Property(type: 'string', maxLength: ShippingAddress::MAX_CITY_LENGTH, description: 'City, 1 to 60 characters once trimmed of white space and invisible characters at the ends, with a visible character, without control characters or line separators. The country is always Poland.', example: 'Warszawa')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Miejscowość jest wymagana.'),
            new Assert\Type('string', message: 'Miejscowość jest wymagana.'),
            new Assert\NotBlank(message: 'Miejscowość jest wymagana.', normalizer: [Text::class, 'normalize']),
            new Assert\Length(max: ShippingAddress::MAX_CITY_LENGTH, maxMessage: 'Miejscowość może mieć najwyżej 60 znaków.', charsetMessage: 'Miejscowość zawiera niedozwolone znaki.', normalizer: [Text::class, 'normalize']),
            new TextCharacters(message: 'Miejscowość zawiera niedozwolone znaki.'),
        ])]
        public mixed $city,
        #[OA\Property(type: 'integer', minimum: 0, description: 'The gross total the customer saw, in grosze, with the delivery: `totalGross` of the chosen option of `delivery.options` of the cart. Only compared with the total the API computes; another one is a 409.', example: 39998)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kwota zamówienia jest wymagana.'),
            new Assert\Type('integer', message: 'Kwota zamówienia musi być liczbą całkowitą.'),
            new Assert\PositiveOrZero(message: 'Kwota zamówienia nie może być ujemna.'),
        ])]
        public mixed $expectedTotalGross,
        #[OA\Property(type: 'string', format: 'uuid', description: 'Id of the delivery method the customer chose in the checkout (`methodId` of an option of `delivery.options` of the cart): a UUID in any letter case. Required: a request without it (a checkout from before the delivery) is a 422 asking to refresh the page. A method that is unknown or no longer active is a 409 `delivery-method-unavailable`; its cost is part of the total compared with `expectedTotalGross`.')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy.'),
            new Assert\Type('string', message: 'Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go ponownie.'),
            new Assert\NotBlank(message: 'Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go ponownie.'),
            new Assert\Regex(pattern: PlaceGuestOrderRequest::CART_ID_PATTERN, message: 'Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go ponownie.'),
        ])]
        public mixed $deliveryMethodId,
        #[OA\Property(
            type: 'array',
            minItems: 1,
            maxItems: Order::MAX_LINES,
            description: PlaceGuestOrderRequest::EXPECTED_LINES_DESCRIPTION,
            items: new OA\Items(type: 'object', required: ['variantId', 'quantity'], properties: [
                new OA\Property(property: 'variantId', type: 'string', format: 'uuid', description: 'Id of the variant of a line of the summary (`variantId` of a line of the cart): a UUID in any letter case.'),
                new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: OrderLine::MAX_QUANTITY, description: 'The units of the line the summary showed (`quantity` of the line of the cart).'),
            ]),
        )]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kasa została zaktualizowana. Odśwież stronę.'),
            new ExpectedLinesFormat(),
        ])]
        public mixed $expectedLines,
        #[OA\Property(type: 'string', nullable: true, maxLength: DiscountCodeText::MAX_LENGTH, description: 'The discount code the customer applied in the checkout, optional: a missing key, `null`, an empty text or one of white space only is no code. Compared without letter case. A code that gives no discount is a 409 with its own `type`; the order is not placed.', example: 'WITAJ10')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: 'Kod rabatowy musi być tekstem.'),
            new Assert\Length(max: DiscountCodeText::MAX_LENGTH, maxMessage: 'Kod rabatowy może mieć najwyżej 64 znaki.'),
        ])]
        public mixed $discountCode = null,
    ) {
    }
}
