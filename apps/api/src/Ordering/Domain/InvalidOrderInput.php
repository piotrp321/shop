<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Data of an order that breaks a rule of its value objects. The API checks the same rules first (with the same
 * messages); the domain keeps them anyway. The message never contains the submitted value.
 */
final class InvalidOrderInput extends \DomainException implements InvalidInput
{
    public static function email(): self
    {
        return new self('Podaj poprawny adres e-mail.');
    }

    public static function fullNameMissing(): self
    {
        return new self('Imię i nazwisko są wymagane.');
    }

    public static function fullNameTooLong(): self
    {
        return new self(\sprintf('Imię i nazwisko mogą mieć najwyżej %d znaków.', Contact::MAX_FULL_NAME_LENGTH));
    }

    public static function fullNameCharacters(): self
    {
        return new self('Imię i nazwisko zawierają niedozwolone znaki.');
    }

    public static function phone(): self
    {
        return new self('Podaj 9-cyfrowy numer telefonu.');
    }

    public static function streetMissing(): self
    {
        return new self('Ulica i numer są wymagane.');
    }

    public static function streetTooLong(): self
    {
        return new self(\sprintf('Ulica i numer mogą mieć najwyżej %d znaków.', ShippingAddress::MAX_STREET_LENGTH));
    }

    public static function streetCharacters(): self
    {
        return new self('Ulica i numer zawierają niedozwolone znaki.');
    }

    public static function postalCodeMissing(): self
    {
        return new self('Kod pocztowy jest wymagany.');
    }

    public static function postalCode(): self
    {
        return new self('Podaj kod pocztowy w formacie 00-000.');
    }

    public static function cityMissing(): self
    {
        return new self('Miejscowość jest wymagana.');
    }

    public static function cityTooLong(): self
    {
        return new self(\sprintf('Miejscowość może mieć najwyżej %d znaków.', ShippingAddress::MAX_CITY_LENGTH));
    }

    public static function cityCharacters(): self
    {
        return new self('Miejscowość zawiera niedozwolone znaki.');
    }

    public static function idempotencyKey(): self
    {
        return new self('Nieprawidłowy klucz żądania.');
    }

    public static function noteMissing(): self
    {
        return new self('Notatka jest wymagana.');
    }

    public static function noteTooLong(): self
    {
        return new self(\sprintf('Notatka może mieć najwyżej %d znaków.', StaffNote::MAX_LENGTH));
    }

    public static function noteCharacters(): self
    {
        return new self('Notatka zawiera niedozwolone znaki.');
    }
}
