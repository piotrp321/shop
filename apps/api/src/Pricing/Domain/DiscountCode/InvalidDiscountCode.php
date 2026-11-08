<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A discount code breaks a rule of its own values. The field names the place of the value in a request (the HTTP
 * layer shows the message at that field); the message is Polish text for the staff member, without the value.
 */
final class InvalidDiscountCode extends \DomainException implements InvalidInput
{
    public const string CODE = 'code';
    public const string VALUE = 'value';
    public const string MINIMUM_ORDER = 'minimumOrderGross';
    public const string ENDS_AT = 'endsAt';
    public const string USAGE_LIMIT = 'usageLimit';

    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function code(): self
    {
        return new self(self::CODE, 'Kod ma 3–32 znaki: litery, cyfry i myślnik.');
    }

    public static function percent(): self
    {
        return new self(self::VALUE, 'Rabat procentowy to 1–90%.');
    }

    public static function amount(): self
    {
        return new self(self::VALUE, 'Kwota rabatu to 1–10 000 zł.');
    }

    public static function minimumNotAboveAmount(): self
    {
        return new self(self::MINIMUM_ORDER, 'Kod kwotowy wymaga minimum większego od kwoty rabatu.');
    }

    public static function minimum(): self
    {
        return new self(self::MINIMUM_ORDER, 'Minimum to 0,01–1 000 000 zł.');
    }

    public static function endNotAfterStart(): self
    {
        return new self(self::ENDS_AT, 'Koniec musi być po początku.');
    }

    public static function endInThePast(): self
    {
        return new self(self::ENDS_AT, 'Koniec musi być w przyszłości.');
    }

    public static function usageLimit(): self
    {
        return new self(self::USAGE_LIMIT, 'Limit to 1–1 000 000 użyć.');
    }

    public static function limitBelowUses(int $activeUses): self
    {
        return new self(self::USAGE_LIMIT, 'Limit nie może być mniejszy od liczby użyć ('.$activeUses.').');
    }
}
