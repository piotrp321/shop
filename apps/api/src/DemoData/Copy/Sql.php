<?php

declare(strict_types=1);

namespace App\DemoData\Copy;

/**
 * The values DBAL returns as mixed, checked and converted to the type the caller expects.
 */
final class Sql
{
    private function __construct()
    {
    }

    public static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/D', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Expected an integer from the database, got %s.', get_debug_type($value)));
    }

    public static function float(mixed $value): float
    {
        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new \UnexpectedValueException(\sprintf('Expected a number from the database, got %s.', get_debug_type($value)));
    }

    public static function string(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        throw new \UnexpectedValueException(\sprintf('Expected a string from the database, got %s.', get_debug_type($value)));
    }

    /**
     * A PostgreSQL array literal of strings, bound as one parameter and cast on the statement (`?::text[]`, `?::uuid[]`).
     *
     * @param list<string> $values
     */
    public static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"', $values)).'}';
    }
}
