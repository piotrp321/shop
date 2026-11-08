<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A promotion breaks a rule of its own values. The field names the place of the value in a request (the HTTP layer
 * shows the message at that field); the message is Polish text for the staff member, without the value.
 */
final class InvalidPromotion extends \DomainException implements InvalidInput
{
    public const string NAME = 'name';
    public const string PERCENT_OFF = 'percentOff';
    public const string STARTS_AT = 'startsAt';
    public const string ENDS_AT = 'endsAt';
    public const string VARIANT_IDS = 'variantIds';

    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function name(): self
    {
        return new self(self::NAME, 'Nazwa promocji musi mieć od 1 do 100 znaków, bez znaków sterujących.');
    }

    public static function percentOff(): self
    {
        return new self(self::PERCENT_OFF, 'Obniżka musi wynosić od 1 do 90%.');
    }

    public static function endInThePast(): self
    {
        return new self(self::ENDS_AT, 'Koniec promocji musi być w przyszłości.');
    }

    public static function endNotAfterStart(): self
    {
        return new self(self::ENDS_AT, 'Koniec promocji musi być później niż jej początek.');
    }

    public static function tooLong(): self
    {
        return new self(self::ENDS_AT, 'Promocja może trwać najwyżej 90 dni.');
    }

    public static function variantCount(): self
    {
        return new self(self::VARIANT_IDS, 'Promocja musi mieć od 1 do 1000 wariantów.');
    }

    public static function repeatedVariant(): self
    {
        return new self(self::VARIANT_IDS, 'Wariant może być w promocji tylko raz.');
    }
}
