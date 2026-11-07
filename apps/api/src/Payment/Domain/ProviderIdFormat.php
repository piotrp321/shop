<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The format of the identifiers a provider gives (its payment, its webhook event): 1 to 100 characters
 * [A-Za-z0-9_-].
 */
final class ProviderIdFormat
{
    private const string PATTERN = '/^[A-Za-z0-9_-]{1,100}$/D';

    /**
     * @throws \InvalidArgumentException
     */
    public static function check(string $value, string $name): string
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException($name.' is 1 to 100 letters, digits, underscores or hyphens.');
        }

        return $value;
    }
}
