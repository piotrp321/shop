<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The discount code of a placement as it is quoted and fingerprinted: valid UTF-8 (bad bytes replaced), without the
 * Unicode spaces and invisible format characters at its ends, ASCII letters in upper case (like Pricing reads a code).
 * Nothing left is no code.
 */
final class DiscountCodeText
{
    /** Longer than any code (Pricing's codes have at most 32 characters); the API refuses a longer text first. */
    public const int MAX_LENGTH = 64;

    /**
     * @throws InvalidOrderInput more than MAX_LENGTH characters
     */
    public static function normalised(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }
        $valid = mb_scrub($code, 'UTF-8');
        if (mb_strlen($valid, 'UTF-8') > self::MAX_LENGTH) {
            throw InvalidOrderInput::discountCodeTooLong();
        }
        $text = strtoupper(preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $valid) ?? $valid);

        return '' === $text ? null : $text;
    }
}
