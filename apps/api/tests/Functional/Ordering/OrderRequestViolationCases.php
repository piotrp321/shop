<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

/**
 * The invalid bodies of an order with the violations they must give (spec checkout, section "API"): every message is
 * Polish, never carries the input, and one field gives at most one violation. Each case changes a valid body: a
 * field set to a value, or removed (MISSING). Data providers for the guest and the customer tests.
 */
trait OrderRequestViolationCases
{
    private const string MISSING = "\0missing";

    private const string CART_REQUIRED = 'Identyfikator koszyka jest wymagany.';
    private const string CART_UUID = 'Identyfikator koszyka musi być UUID.';
    private const string EMAIL_REQUIRED = 'Adres e-mail jest wymagany.';
    private const string EMAIL_INVALID = 'Podaj poprawny adres e-mail.';
    private const string NAME_REQUIRED = 'Imię i nazwisko są wymagane.';
    private const string NAME_TOO_LONG = 'Imię i nazwisko mogą mieć najwyżej 100 znaków.';
    private const string NAME_CONTROL = 'Imię i nazwisko zawierają niedozwolone znaki.';
    private const string PHONE_REQUIRED = 'Numer telefonu jest wymagany.';
    private const string PHONE_INVALID = 'Podaj 9-cyfrowy numer telefonu.';
    private const string STREET_REQUIRED = 'Ulica i numer są wymagane.';
    private const string STREET_TOO_LONG = 'Ulica i numer mogą mieć najwyżej 100 znaków.';
    private const string STREET_CONTROL = 'Ulica i numer zawierają niedozwolone znaki.';
    private const string POSTAL_CODE_REQUIRED = 'Kod pocztowy jest wymagany.';
    private const string POSTAL_CODE_INVALID = 'Podaj kod pocztowy w formacie 00-000.';
    private const string CITY_REQUIRED = 'Miejscowość jest wymagana.';
    private const string CITY_TOO_LONG = 'Miejscowość może mieć najwyżej 60 znaków.';
    private const string CITY_CONTROL = 'Miejscowość zawiera niedozwolone znaki.';
    private const string TOTAL_REQUIRED = 'Kwota zamówienia jest wymagana.';
    private const string TOTAL_INTEGER = 'Kwota zamówienia musi być liczbą całkowitą.';
    private const string TOTAL_NEGATIVE = 'Kwota zamówienia nie może być ujemna.';
    private const string CODE_TEXT = 'Kod rabatowy musi być tekstem.';
    private const string CODE_TOO_LONG = 'Kod rabatowy może mieć najwyżej 64 znaki.';

    /**
     * The fields both endpoints take: contact (without the email) and address, and the expected total.
     *
     * @return iterable<string, array{array<string, mixed>, list<array{string, string}>}>
     */
    public static function invalidDetails(): iterable
    {
        foreach (['fullName' => self::NAME_REQUIRED, 'phone' => self::PHONE_REQUIRED, 'street' => self::STREET_REQUIRED, 'postalCode' => self::POSTAL_CODE_REQUIRED, 'city' => self::CITY_REQUIRED] as $field => $required) {
            yield "no $field" => [[$field => self::MISSING], [[$field, $required]]];
            yield "null $field" => [[$field => null], [[$field, $required]]];
            yield "empty $field" => [[$field => ''], [[$field, $required]]];
        }
        yield 'fullName of spaces' => [['fullName' => '   '], [['fullName', self::NAME_REQUIRED]]];
        yield 'fullName a number' => [['fullName' => 5], [['fullName', self::NAME_REQUIRED]]];
        yield 'fullName of 101 characters' => [['fullName' => str_repeat('ż', 101)], [['fullName', self::NAME_TOO_LONG]]];
        yield 'fullName of 101 characters once trimmed' => [['fullName' => ' '.str_repeat('a', 101).' '], [['fullName', self::NAME_TOO_LONG]]];
        // Control characters \x00-\x1F and \x7F, also at the ends, where a trim would drop them silently.
        yield 'fullName with a NUL' => [['fullName' => "Anna\0Evil"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with a trailing NUL' => [['fullName' => "Anna\0"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with a line break' => [['fullName' => "Anna\nNowak"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with a trailing tab' => [['fullName' => "Anna Nowak\t"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with DEL' => [['fullName' => "Anna\x7FNowak"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with U+0085' => [['fullName' => "Anna\u{0085}Nowak"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with U+2028' => [['fullName' => "Anna\u{2028}Nowak"], [['fullName', self::NAME_CONTROL]]];
        yield 'fullName with a trailing U+2029' => [['fullName' => "Anna Nowak\u{2029}"], [['fullName', self::NAME_CONTROL]]];
        yield 'street with U+2028' => [['street' => "ul. Długa\u{2028}5"], [['street', self::STREET_CONTROL]]];
        yield 'street with a leading U+0085' => [['street' => "\u{0085}ul. Długa 5"], [['street', self::STREET_CONTROL]]];
        yield 'city with U+2029' => [['city' => "War\u{2029}szawa"], [['city', self::CITY_CONTROL]]];
        yield 'city with a trailing U+2028' => [['city' => "Warszawa\u{2028}"], [['city', self::CITY_CONTROL]]];
        yield 'postalCode with U+2028' => [['postalCode' => "00-950\u{2028}"], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'street with a NUL' => [['street' => "ul. Długa\0 5"], [['street', self::STREET_CONTROL]]];
        yield 'street with a leading line break' => [['street' => "\nul. Długa 5"], [['street', self::STREET_CONTROL]]];
        yield 'city with a NUL' => [['city' => "War\0szawa"], [['city', self::CITY_CONTROL]]];
        yield 'city with a carriage return' => [['city' => "Warszawa\r"], [['city', self::CITY_CONTROL]]];
        // Valid but for the control character; at the ends a trim would drop it before the format is checked.
        yield 'phone with a trailing NUL' => [['phone' => "600100200\0"], [['phone', self::PHONE_INVALID]]];
        yield 'phone with a leading line break' => [['phone' => "\n600100200"], [['phone', self::PHONE_INVALID]]];
        yield 'phone with a tab inside' => [['phone' => "600\t100200"], [['phone', self::PHONE_INVALID]]];
        yield 'phone with a trailing tab' => [['phone' => "600100200\t"], [['phone', self::PHONE_INVALID]]];
        yield 'postalCode with a trailing NUL' => [['postalCode' => "00-950\0"], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode with a trailing tab' => [['postalCode' => "00-950\t"], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode with a leading line break' => [['postalCode' => "\n00-950"], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode with a carriage return inside' => [['postalCode' => "00-9\r50"], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'phone of spaces' => [['phone' => '  '], [['phone', self::PHONE_REQUIRED]]];
        yield 'phone of 8 digits' => [['phone' => '60010020'], [['phone', self::PHONE_INVALID]]];
        yield 'phone of 10 digits' => [['phone' => '6001002000'], [['phone', self::PHONE_INVALID]]];
        yield 'phone with another country code' => [['phone' => '+49600100200'], [['phone', self::PHONE_INVALID]]];
        yield 'phone with a letter' => [['phone' => '60010020a'], [['phone', self::PHONE_INVALID]]];
        yield 'phone with a line break' => [['phone' => "600100200\n"], [['phone', self::PHONE_INVALID]]];
        yield 'phone a number' => [['phone' => 600100200], [['phone', self::PHONE_INVALID]]];
        yield 'street of spaces' => [['street' => '  '], [['street', self::STREET_REQUIRED]]];
        yield 'street a number' => [['street' => 5], [['street', self::STREET_REQUIRED]]];
        yield 'street of 101 characters' => [['street' => str_repeat('ł', 101)], [['street', self::STREET_TOO_LONG]]];
        yield 'postalCode of spaces' => [['postalCode' => ' '], [['postalCode', self::POSTAL_CODE_REQUIRED]]];
        yield 'postalCode without the hyphen' => [['postalCode' => '00950'], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode too short' => [['postalCode' => '00-95'], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode with a letter' => [['postalCode' => '0A-950'], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode with a line break' => [['postalCode' => "00-950\n0"], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'postalCode a number' => [['postalCode' => 950], [['postalCode', self::POSTAL_CODE_INVALID]]];
        yield 'city of spaces' => [['city' => '  '], [['city', self::CITY_REQUIRED]]];
        yield 'city a number' => [['city' => 5], [['city', self::CITY_REQUIRED]]];
        yield 'city of 61 characters' => [['city' => str_repeat('ś', 61)], [['city', self::CITY_TOO_LONG]]];
        yield 'no expectedTotalGross' => [['expectedTotalGross' => self::MISSING], [['expectedTotalGross', self::TOTAL_REQUIRED]]];
        yield 'null expectedTotalGross' => [['expectedTotalGross' => null], [['expectedTotalGross', self::TOTAL_REQUIRED]]];
        yield 'expectedTotalGross in a string' => [['expectedTotalGross' => '100'], [['expectedTotalGross', self::TOTAL_INTEGER]]];
        yield 'expectedTotalGross a fraction' => [['expectedTotalGross' => 1.5], [['expectedTotalGross', self::TOTAL_INTEGER]]];
        yield 'expectedTotalGross a whole fraction' => [['expectedTotalGross' => 2.0], [['expectedTotalGross', self::TOTAL_INTEGER]]];
        yield 'expectedTotalGross a boolean' => [['expectedTotalGross' => true], [['expectedTotalGross', self::TOTAL_INTEGER]]];
        yield 'expectedTotalGross an array' => [['expectedTotalGross' => [100]], [['expectedTotalGross', self::TOTAL_INTEGER]]];
        yield 'expectedTotalGross negative' => [['expectedTotalGross' => -1], [['expectedTotalGross', self::TOTAL_NEGATIVE]]];
        yield 'discountCode a number' => [['discountCode' => 10], [['discountCode', self::CODE_TEXT]]];
        yield 'discountCode a boolean' => [['discountCode' => true], [['discountCode', self::CODE_TEXT]]];
        yield 'discountCode an array' => [['discountCode' => ['WITAJ10']], [['discountCode', self::CODE_TEXT]]];
        yield 'discountCode of 65 characters' => [['discountCode' => str_repeat('Ą', 65)], [['discountCode', self::CODE_TOO_LONG]]];
        yield 'several fields wrong' => [
            ['fullName' => '', 'phone' => '123', 'expectedTotalGross' => '1', 'discountCode' => str_repeat('A', 65)],
            [['fullName', self::NAME_REQUIRED], ['phone', self::PHONE_INVALID], ['expectedTotalGross', self::TOTAL_INTEGER], ['discountCode', self::CODE_TOO_LONG]],
        ];
    }

    /**
     * The fields only the guest sends: the cart id and the email, then everything of invalidDetails().
     *
     * @return iterable<string, array{array<string, mixed>, list<array{string, string}>}>
     */
    public static function invalidGuestBodies(): iterable
    {
        yield 'no cartId' => [['cartId' => self::MISSING], [['cartId', self::CART_REQUIRED]]];
        yield 'null cartId' => [['cartId' => null], [['cartId', self::CART_REQUIRED]]];
        yield 'cartId a number' => [['cartId' => 5], [['cartId', self::CART_UUID]]];
        yield 'cartId a boolean' => [['cartId' => true], [['cartId', self::CART_UUID]]];
        yield 'cartId an array' => [['cartId' => ['0192f0c4-7c1a-7c4e-9a6e-000000000001']], [['cartId', self::CART_UUID]]];
        yield 'cartId empty' => [['cartId' => ''], [['cartId', self::CART_UUID]]];
        yield 'cartId not a uuid' => [['cartId' => 'not-a-uuid'], [['cartId', self::CART_UUID]]];
        yield 'cartId of 37 characters with a wrong version and variant' => [['cartId' => '0192f0c4-7c1a-0c4e-0a6e-0000000000011'], [['cartId', self::CART_UUID]]];
        yield 'cartId of 35 characters' => [['cartId' => '0192f0c4-7c1a-7c4e-9a6e-00000000001'], [['cartId', self::CART_UUID]]];
        yield 'cartId without hyphens' => [['cartId' => '0192f0c47c1a7c4e9a6e000000000001'], [['cartId', self::CART_UUID]]];
        yield 'cartId in braces' => [['cartId' => '{0192f0c4-7c1a-7c4e-9a6e-000000000001}'], [['cartId', self::CART_UUID]]];
        yield 'cartId with a line break' => [['cartId' => "0192f0c4-7c1a-7c4e-9a6e-000000000001\n"], [['cartId', self::CART_UUID]]];
        yield 'no email' => [['email' => self::MISSING], [['email', self::EMAIL_REQUIRED]]];
        yield 'null email' => [['email' => null], [['email', self::EMAIL_REQUIRED]]];
        yield 'empty email' => [['email' => ''], [['email', self::EMAIL_REQUIRED]]];
        yield 'email of spaces' => [['email' => '   '], [['email', self::EMAIL_REQUIRED]]];
        yield 'email without @' => [['email' => 'anna.example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email without a domain' => [['email' => 'anna@'], [['email', self::EMAIL_INVALID]]];
        yield 'email with a space inside' => [['email' => 'anna nowak@example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email with two dots in a row' => [['email' => 'a..b@example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email with a leading dot' => [['email' => '.a@example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email with a trailing dot before the @' => [['email' => 'a.@example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email with a NUL' => [['email' => "anna\0@example.com"], [['email', self::EMAIL_INVALID]]];
        yield 'email with a trailing line break' => [['email' => "anna@example.com\n"], [['email', self::EMAIL_INVALID]]];
        yield 'email with U+2028' => [['email' => "anna\u{2028}@example.com"], [['email', self::EMAIL_INVALID]]];
        yield 'email with a trailing U+0085' => [['email' => "anna@example.com\u{0085}"], [['email', self::EMAIL_INVALID]]];
        yield 'email with a leading U+2029' => [['email' => "\u{2029}anna@example.com"], [['email', self::EMAIL_INVALID]]];
        yield 'email of 255 characters' => [['email' => str_repeat('a', 243).'@example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email a number' => [['email' => 5], [['email', self::EMAIL_INVALID]]];
        yield 'email an array' => [['email' => ['anna@example.com']], [['email', self::EMAIL_INVALID]]];
        yield from self::invalidDetails();
        yield 'every field missing' => [
            array_fill_keys(['cartId', 'email', 'fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross'], self::MISSING),
            [
                ['cartId', self::CART_REQUIRED],
                ['email', self::EMAIL_REQUIRED],
                ['fullName', self::NAME_REQUIRED],
                ['phone', self::PHONE_REQUIRED],
                ['street', self::STREET_REQUIRED],
                ['postalCode', self::POSTAL_CODE_REQUIRED],
                ['city', self::CITY_REQUIRED],
                ['expectedTotalGross', self::TOTAL_REQUIRED],
            ],
        ];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{string, string}>}>
     */
    public static function invalidCustomerBodies(): iterable
    {
        yield from self::invalidDetails();
        yield 'every field missing' => [
            array_fill_keys(['fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross'], self::MISSING),
            [
                ['fullName', self::NAME_REQUIRED],
                ['phone', self::PHONE_REQUIRED],
                ['street', self::STREET_REQUIRED],
                ['postalCode', self::POSTAL_CODE_REQUIRED],
                ['city', self::CITY_REQUIRED],
                ['expectedTotalGross', self::TOTAL_REQUIRED],
            ],
        ];
    }

    /**
     * The valid body with the changes of a case applied.
     *
     * @param array<string, mixed> $valid
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function changed(array $valid, array $changes): array
    {
        foreach ($changes as $field => $value) {
            if (self::MISSING === $value) {
                unset($valid[$field]);
            } else {
                $valid[$field] = $value;
            }
        }

        return $valid;
    }
}
