<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\ShippingAddress;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/v1/me/orders. The email is the one of the token, so the body has none (an `email` key is ignored,
 * like any unknown key: lines, quantities and prices never come from the body). Every key is required: the
 * serializer gives `null` for a missing key (the properties are `mixed`). The values are taken as JSON gives them and
 * validated, never cast. The constraints of a field run in order up to the first violation, so a field gives at most
 * one, in Polish; the domain checks the same rules again. The rules are the same as in PlaceGuestOrderRequest.
 */
#[OA\Schema(description: 'The contact and the address of an order of the cart of the customer, and the total the customer saw.', required: ['fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross'])]
final readonly class PlaceCustomerOrderRequest
{
    public function __construct(
        #[OA\Property(type: 'string', maxLength: Contact::MAX_FULL_NAME_LENGTH, description: 'Full name, 1 to 100 characters once trimmed.', example: 'Jan Kowalski')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Imię i nazwisko są wymagane.'),
            new Assert\Type('string', message: 'Imię i nazwisko są wymagane.'),
            new Assert\NotBlank(message: 'Imię i nazwisko są wymagane.', normalizer: 'trim'),
            new Assert\Length(max: Contact::MAX_FULL_NAME_LENGTH, maxMessage: 'Imię i nazwisko mogą mieć najwyżej 100 znaków.', normalizer: 'trim'),
        ])]
        public mixed $fullName,
        #[OA\Property(type: 'string', description: 'A Polish phone number: 9 digits, optionally after +48; spaces and hyphens are allowed and dropped. The order keeps +48 and the 9 digits.', example: '600 100 200')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Numer telefonu jest wymagany.'),
            new Assert\Type('string', message: 'Podaj 9-cyfrowy numer telefonu.'),
            new Assert\NotBlank(message: 'Numer telefonu jest wymagany.', normalizer: 'trim'),
            new Assert\Regex(pattern: PlaceGuestOrderRequest::PHONE_PATTERN, message: 'Podaj 9-cyfrowy numer telefonu.'),
        ])]
        public mixed $phone,
        #[OA\Property(type: 'string', maxLength: ShippingAddress::MAX_STREET_LENGTH, description: 'Street and number, 1 to 100 characters once trimmed.', example: 'ul. Długa 5/3')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Ulica i numer są wymagane.'),
            new Assert\Type('string', message: 'Ulica i numer są wymagane.'),
            new Assert\NotBlank(message: 'Ulica i numer są wymagane.', normalizer: 'trim'),
            new Assert\Length(max: ShippingAddress::MAX_STREET_LENGTH, maxMessage: 'Ulica i numer mogą mieć najwyżej 100 znaków.', normalizer: 'trim'),
        ])]
        public mixed $street,
        #[OA\Property(type: 'string', description: 'Polish postal code NN-NNN.', example: '00-950')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kod pocztowy jest wymagany.'),
            new Assert\Type('string', message: 'Podaj kod pocztowy w formacie 00-000.'),
            new Assert\NotBlank(message: 'Kod pocztowy jest wymagany.', normalizer: 'trim'),
            new Assert\Regex(pattern: PlaceGuestOrderRequest::POSTAL_CODE_PATTERN, message: 'Podaj kod pocztowy w formacie 00-000.', normalizer: 'trim'),
        ])]
        public mixed $postalCode,
        #[OA\Property(type: 'string', maxLength: ShippingAddress::MAX_CITY_LENGTH, description: 'City, 1 to 60 characters once trimmed. The country is always Poland.', example: 'Warszawa')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Miejscowość jest wymagana.'),
            new Assert\Type('string', message: 'Miejscowość jest wymagana.'),
            new Assert\NotBlank(message: 'Miejscowość jest wymagana.', normalizer: 'trim'),
            new Assert\Length(max: ShippingAddress::MAX_CITY_LENGTH, maxMessage: 'Miejscowość może mieć najwyżej 60 znaków.', normalizer: 'trim'),
        ])]
        public mixed $city,
        #[OA\Property(type: 'integer', minimum: 0, description: 'The gross total of the cart the customer saw, in grosze (`totals.gross` of the cart). Only compared with the total the API computes; another one is a 409.', example: 39998)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kwota zamówienia jest wymagana.'),
            new Assert\Type('integer', message: 'Kwota zamówienia musi być liczbą całkowitą.'),
            new Assert\PositiveOrZero(message: 'Kwota zamówienia nie może być ujemna.'),
        ])]
        public mixed $expectedTotalGross,
    ) {
    }
}
