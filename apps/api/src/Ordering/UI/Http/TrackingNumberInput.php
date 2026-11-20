<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\TrackingNumber;

/**
 * The rules of the `trackingNumber` of the requests of the panel (docs/features/delivery/spec.md, W8 and W8a), with
 * their messages, so a staff member sees them at the field. The value is taken as JSON gave it, never cast.
 */
final class TrackingNumberInput
{
    public const string ONLY_WITH_SHIPMENT = 'Numer przesyłki można podać tylko przy wysyłce.';
    public const string NOT_TEXT = 'Numer przesyłki musi być tekstem.';

    /**
     * The violation of a value that is to be a number or none: not a text, or a text of another shape. Null and
     * nothing but Unicode whitespace is no number and no violation.
     */
    public static function violation(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            return self::NOT_TEXT;
        }
        try {
            TrackingNumber::optional($value);
        } catch (InvalidOrderInput $invalid) {
            return $invalid->getMessage();
        }

        return null;
    }

    /**
     * Whether the value says no number: null or nothing but Unicode whitespace.
     */
    public static function isNone(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (!\is_string($value)) {
            return false;
        }
        try {
            return null === TrackingNumber::optional($value);
        } catch (InvalidOrderInput) {
            return false;
        }
    }
}
