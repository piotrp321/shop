<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\ControlCharacters;
use App\Ordering\Domain\DiscountCodeText;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\UI\Http\Validation\MailerAcceptsEmail;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/v1/orders: the guest cart, the email, then the same fields with the same rules as
 * PlaceCustomerOrderRequest. Unknown keys are ignored: lines, quantities and prices never come from the body. Every
 * key but `discountCode` is required: the serializer gives `null` for a missing key (the properties are `mixed`). The values are taken
 * as JSON gives them and validated, never cast. The constraints of a field run in order up to the first violation,
 * so a field gives at most one, in Polish; the domain checks the same rules again. Names, street, city and email
 * refuse control characters, in the value as it came (before any trim).
 */
#[OA\Schema(description: 'The guest cart, the contact and the address of an order, the total the guest saw and the discount code applied.', required: ['cartId', 'email', 'fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross'])]
final readonly class PlaceGuestOrderRequest
{
    /**
     * The format of a cart id of Cart (CartId): 8-4-4-4-12 hex digits in any letter case, of any version and variant.
     * A strict UUID check would refuse ids Cart takes (and give several violations for one value); an id of this
     * format without a cart is a 404, like an unknown cart.
     */
    public const string CART_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /**
     * 9 digits, optionally after +48, with any spaces and hyphens: what Contact accepts once it drops them.
     */
    public const string PHONE_PATTERN = '/^[ -]*(?:\+[ -]*4[ -]*8)?(?:[ -]*[0-9]){9}[ -]*$/D';
    public const string POSTAL_CODE_PATTERN = '/^[0-9]{2}-[0-9]{3}$/D';

    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid', description: 'Id of the guest cart: a UUID, 8-4-4-4-12 hex digits in any letter case. An unknown, expired or merged cart, or the id of a customer cart, is a 404.')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Identyfikator koszyka jest wymagany.'),
            new Assert\Type('string', message: 'Identyfikator koszyka musi być UUID.'),
            new Assert\NotBlank(message: 'Identyfikator koszyka musi być UUID.'),
            new Assert\Regex(pattern: self::CART_ID_PATTERN, message: 'Identyfikator koszyka musi być UUID.'),
        ])]
        public mixed $cartId,
        #[OA\Property(type: 'string', format: 'email', maxLength: EmailAddress::MAX_LENGTH, description: 'Email address the order is confirmed to, at most 254 characters once trimmed, one the mailer accepts (no empty part between dots).', example: 'anna.nowak@example.com')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Adres e-mail jest wymagany.'),
            new Assert\Type('string', message: 'Podaj poprawny adres e-mail.'),
            new Assert\NotBlank(message: 'Adres e-mail jest wymagany.', normalizer: 'trim'),
            new Assert\Length(max: EmailAddress::MAX_LENGTH, maxMessage: 'Podaj poprawny adres e-mail.', charsetMessage: 'Podaj poprawny adres e-mail.', normalizer: 'trim'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Podaj poprawny adres e-mail.'),
            new Assert\Email(message: 'Podaj poprawny adres e-mail.', normalizer: 'trim'),
            new MailerAcceptsEmail(),
        ])]
        public mixed $email,
        #[OA\Property(type: 'string', maxLength: Contact::MAX_FULL_NAME_LENGTH, description: 'Full name, 1 to 100 characters once trimmed.', example: 'Anna Nowak')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Imię i nazwisko są wymagane.'),
            new Assert\Type('string', message: 'Imię i nazwisko są wymagane.'),
            new Assert\NotBlank(message: 'Imię i nazwisko są wymagane.', normalizer: 'trim'),
            new Assert\Length(max: Contact::MAX_FULL_NAME_LENGTH, maxMessage: 'Imię i nazwisko mogą mieć najwyżej 100 znaków.', charsetMessage: 'Imię i nazwisko zawierają niedozwolone znaki.', normalizer: 'trim'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Imię i nazwisko zawierają niedozwolone znaki.'),
        ])]
        public mixed $fullName,
        #[OA\Property(type: 'string', description: 'A Polish phone number: 9 digits, optionally after +48; spaces and hyphens are allowed and dropped. The order keeps +48 and the 9 digits.', example: '600 100 200')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Numer telefonu jest wymagany.'),
            new Assert\Type('string', message: 'Podaj 9-cyfrowy numer telefonu.'),
            new Assert\NotBlank(message: 'Numer telefonu jest wymagany.', normalizer: 'trim'),
            new Assert\Regex(pattern: self::PHONE_PATTERN, message: 'Podaj 9-cyfrowy numer telefonu.'),
        ])]
        public mixed $phone,
        #[OA\Property(type: 'string', maxLength: ShippingAddress::MAX_STREET_LENGTH, description: 'Street and number, 1 to 100 characters once trimmed.', example: 'ul. Długa 5/3')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Ulica i numer są wymagane.'),
            new Assert\Type('string', message: 'Ulica i numer są wymagane.'),
            new Assert\NotBlank(message: 'Ulica i numer są wymagane.', normalizer: 'trim'),
            new Assert\Length(max: ShippingAddress::MAX_STREET_LENGTH, maxMessage: 'Ulica i numer mogą mieć najwyżej 100 znaków.', charsetMessage: 'Ulica i numer zawierają niedozwolone znaki.', normalizer: 'trim'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Ulica i numer zawierają niedozwolone znaki.'),
        ])]
        public mixed $street,
        #[OA\Property(type: 'string', description: 'Polish postal code NN-NNN.', example: '00-950')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kod pocztowy jest wymagany.'),
            new Assert\Type('string', message: 'Podaj kod pocztowy w formacie 00-000.'),
            new Assert\NotBlank(message: 'Kod pocztowy jest wymagany.', normalizer: 'trim'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Podaj kod pocztowy w formacie 00-000.'),
            new Assert\Regex(pattern: self::POSTAL_CODE_PATTERN, message: 'Podaj kod pocztowy w formacie 00-000.', normalizer: 'trim'),
        ])]
        public mixed $postalCode,
        #[OA\Property(type: 'string', maxLength: ShippingAddress::MAX_CITY_LENGTH, description: 'City, 1 to 60 characters once trimmed. The country is always Poland.', example: 'Warszawa')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Miejscowość jest wymagana.'),
            new Assert\Type('string', message: 'Miejscowość jest wymagana.'),
            new Assert\NotBlank(message: 'Miejscowość jest wymagana.', normalizer: 'trim'),
            new Assert\Length(max: ShippingAddress::MAX_CITY_LENGTH, maxMessage: 'Miejscowość może mieć najwyżej 60 znaków.', charsetMessage: 'Miejscowość zawiera niedozwolone znaki.', normalizer: 'trim'),
            new Assert\Regex(pattern: ControlCharacters::PATTERN, match: false, message: 'Miejscowość zawiera niedozwolone znaki.'),
        ])]
        public mixed $city,
        #[OA\Property(type: 'integer', minimum: 0, description: 'The gross total of the cart the guest saw, in grosze (`totals.gross` of the cart). Only compared with the total the API computes; another one is a 409.', example: 39998)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Kwota zamówienia jest wymagana.'),
            new Assert\Type('integer', message: 'Kwota zamówienia musi być liczbą całkowitą.'),
            new Assert\PositiveOrZero(message: 'Kwota zamówienia nie może być ujemna.'),
        ])]
        public mixed $expectedTotalGross,
        #[OA\Property(type: 'string', nullable: true, maxLength: DiscountCodeText::MAX_LENGTH, description: 'The discount code the customer applied in the checkout, optional: a missing key, `null`, an empty text or one of white space only is no code. Compared without letter case. A code that gives no discount is a 409 with its own `type`; the order is not placed.', example: 'WITAJ10')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: 'Kod rabatowy musi być tekstem.'),
            new Assert\Length(max: DiscountCodeText::MAX_LENGTH, maxMessage: 'Kod rabatowy może mieć najwyżej 64 znaki.'),
        ])]
        public mixed $discountCode = null,
    ) {
    }
}
