<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A delivery method breaks a rule of its own values. The field names the place of the value in a request (the HTTP
 * layer shows the message at that field); the message is Polish text for the administrator, without the value.
 */
final class InvalidDeliveryMethod extends \DomainException implements InvalidInput
{
    public const string NAME = 'name';
    public const string DESCRIPTION = 'description';
    public const string PRICE = 'priceGross';
    public const string FREE_FROM = 'freeFromGross';
    public const string POSITION = 'position';
    public const string TRACKING_URL_TEMPLATE = 'trackingUrlTemplate';

    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self(self::NAME, 'Nazwa jest wymagana.');
    }

    public static function nameTooLong(): self
    {
        return new self(self::NAME, 'Nazwa może mieć najwyżej 100 znaków.');
    }

    public static function nameCharacters(): self
    {
        return new self(self::NAME, 'Nazwa zawiera niedozwolone znaki.');
    }

    public static function descriptionTooLong(): self
    {
        return new self(self::DESCRIPTION, 'Opis może mieć najwyżej 200 znaków.');
    }

    public static function descriptionCharacters(): self
    {
        return new self(self::DESCRIPTION, 'Opis zawiera niedozwolone znaki.');
    }

    public static function priceRange(): self
    {
        return new self(self::PRICE, 'Cena musi wynosić od 0,00 do 999 999,99 zł.');
    }

    public static function freeFromRange(): self
    {
        return new self(self::FREE_FROM, 'Próg darmowej dostawy musi wynosić od 0,01 do 999 999 999,99 zł.');
    }

    public static function position(): self
    {
        return new self(self::POSITION, 'Kolejność musi być liczbą całkowitą od 0 do 999.');
    }

    public static function templateTooLong(): self
    {
        return new self(self::TRACKING_URL_TEMPLATE, 'Link śledzenia może mieć najwyżej 500 znaków.');
    }

    public static function templateAddress(): self
    {
        return new self(self::TRACKING_URL_TEMPLATE, 'Link śledzenia musi zaczynać się od https:// i mieć nazwę hosta.');
    }

    public static function templateCharacters(): self
    {
        return new self(self::TRACKING_URL_TEMPLATE, 'Link śledzenia nie może zawierać spacji ani znaków sterujących.');
    }

    public static function templateNumber(): self
    {
        return new self(self::TRACKING_URL_TEMPLATE, 'Link śledzenia musi zawierać dokładnie jedno {numer}.');
    }
}
