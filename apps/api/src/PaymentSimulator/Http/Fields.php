<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict reading of the JSON bodies of the provider APIs: every reader throws {@see InvalidRequest} with the field
 * name, no type is converted.
 */
final class Fields
{
    private const int MAX_URL_LENGTH = 2000;
    private const int MAX_REFERENCE_LENGTH = 100;
    private const string RFC3339_WITH_FRACTION = 'Y-m-d\TH:i:s.uP';
    /** 9999-12-31T23:59:59Z: the last second with a four-digit year. */
    private const int MAX_UNIX_TIME = 253_402_300_799;

    /**
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || array_is_list($data)) {
            throw new InvalidRequest('body');
        }

        $fields = [];
        foreach ($data as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function reference(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || '' === $value || mb_strlen($value) > self::MAX_REFERENCE_LENGTH) {
            throw new InvalidRequest($field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function positiveInt(array $data, string $field): int
    {
        $value = $data[$field] ?? null;
        if (!\is_int($value) || $value < 1) {
            throw new InvalidRequest($field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function currency(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || 1 !== preg_match('/\A[A-Z]{3}\z/', $value)) {
            throw new InvalidRequest($field);
        }

        return $value;
    }

    /**
     * An absolute http(s) URL.
     *
     * @param array<string, mixed> $data
     */
    public static function url(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || \strlen($value) > self::MAX_URL_LENGTH || false === filter_var($value, \FILTER_VALIDATE_URL)) {
            throw new InvalidRequest($field);
        }
        $parts = parse_url($value);
        if (false === $parts || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || '' === ($parts['host'] ?? '')) {
            throw new InvalidRequest($field);
        }

        return $value;
    }

    /**
     * A time as RFC 3339 text, with or without a fraction of a second ("2026-11-06T12:30:00Z",
     * "2026-11-06T14:30:00.250+02:00").
     *
     * @param array<string, mixed> $data
     */
    public static function rfc3339(array $data, string $field): \DateTimeImmutable
    {
        $value = $data[$field] ?? null;
        if (\is_string($value)) {
            foreach ([\DATE_RFC3339, self::RFC3339_WITH_FRACTION] as $format) {
                $time = \DateTimeImmutable::createFromFormat($format, $value);
                $problems = \DateTimeImmutable::getLastErrors();
                // A date that does not exist ("2026-13-45") is parsed with a warning and rolled over; refuse it.
                if (false !== $time && (false === $problems || (0 === $problems['warning_count'] && 0 === $problems['error_count']))) {
                    return $time;
                }
            }
        }

        throw new InvalidRequest($field);
    }

    /**
     * A time as Unix seconds, up to 9999-12-31T23:59:59Z.
     *
     * @param array<string, mixed> $data
     */
    public static function unixTime(array $data, string $field): \DateTimeImmutable
    {
        $seconds = self::positiveInt($data, $field);
        if ($seconds > self::MAX_UNIX_TIME) {
            throw new InvalidRequest($field);
        }

        return new \DateTimeImmutable('@'.$seconds);
    }

    public static function refuse(InvalidRequest $invalid): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_request', 'field' => $invalid->field], Response::HTTP_BAD_REQUEST);
    }

    public static function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }
}
