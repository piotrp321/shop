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
    private const string PHONE_REQUIRED = 'Numer telefonu jest wymagany.';
    private const string PHONE_INVALID = 'Podaj 9-cyfrowy numer telefonu.';
    private const string STREET_REQUIRED = 'Ulica i numer są wymagane.';
    private const string STREET_TOO_LONG = 'Ulica i numer mogą mieć najwyżej 100 znaków.';
    private const string POSTAL_CODE_REQUIRED = 'Kod pocztowy jest wymagany.';
    private const string POSTAL_CODE_INVALID = 'Podaj kod pocztowy w formacie 00-000.';
    private const string CITY_REQUIRED = 'Miejscowość jest wymagana.';
    private const string CITY_TOO_LONG = 'Miejscowość może mieć najwyżej 60 znaków.';
    private const string TOTAL_REQUIRED = 'Kwota zamówienia jest wymagana.';
    private const string TOTAL_INTEGER = 'Kwota zamówienia musi być liczbą całkowitą.';
    private const string TOTAL_NEGATIVE = 'Kwota zamówienia nie może być ujemna.';

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
        yield 'several fields wrong' => [
            ['fullName' => '', 'phone' => '123', 'expectedTotalGross' => '1'],
            [['fullName', self::NAME_REQUIRED], ['phone', self::PHONE_INVALID], ['expectedTotalGross', self::TOTAL_INTEGER]],
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
        yield 'no email' => [['email' => self::MISSING], [['email', self::EMAIL_REQUIRED]]];
        yield 'null email' => [['email' => null], [['email', self::EMAIL_REQUIRED]]];
        yield 'empty email' => [['email' => ''], [['email', self::EMAIL_REQUIRED]]];
        yield 'email of spaces' => [['email' => '   '], [['email', self::EMAIL_REQUIRED]]];
        yield 'email without @' => [['email' => 'anna.example.com'], [['email', self::EMAIL_INVALID]]];
        yield 'email without a domain' => [['email' => 'anna@'], [['email', self::EMAIL_INVALID]]];
        yield 'email with a space inside' => [['email' => 'anna nowak@example.com'], [['email', self::EMAIL_INVALID]]];
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
