<?php

declare(strict_types=1);

namespace App\DemoData\Copy;

/**
 * One line of the text format of COPY FROM STDIN (tab-separated, NULL as \N, backslash escapes), the form that
 * Pdo\Pgsql::copyFromArray takes. The caller encodes JSON itself.
 */
final class CopyRow
{
    public const string NULL_AS = '\N';

    private function __construct()
    {
    }

    /**
     * @param list<mixed> $values scalars, times and null
     *
     * @throws \InvalidArgumentException for a value that has no text form here or a string with a NUL character
     */
    public static function line(array $values): string
    {
        $fields = [];
        foreach ($values as $value) {
            $fields[] = self::field($value);
        }

        return implode("\t", $fields);
    }

    private static function field(mixed $value): string
    {
        if (null === $value) {
            return self::NULL_AS;
        }
        if (\is_bool($value)) {
            return $value ? 't' : 'f';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('A value of type %s cannot be written with COPY.', get_debug_type($value)));
        }
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException('A string with a NUL character cannot be written with COPY.');
        }

        return strtr($value, ['\\' => '\\\\', "\t" => '\t', "\n" => '\n', "\r" => '\r']);
    }
}
