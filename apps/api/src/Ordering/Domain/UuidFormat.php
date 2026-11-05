<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The one check of the UUID identifiers of Ordering (its own and the copied ones of other modules).
 */
final class UuidFormat
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * @return string the UUID in lower case
     *
     * @throws \InvalidArgumentException
     */
    public static function normalise(string $value, string $name): string
    {
        $value = strtolower($value);
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException($name.' must be a UUID.');
        }

        return $value;
    }
}
