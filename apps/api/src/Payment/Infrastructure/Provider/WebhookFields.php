<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Provider;

use App\Payment\Application\InvalidWebhook;
use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;

/**
 * Strict reading of the webhook bodies of the providers, once their signature is verified: no type is converted,
 * and every fault is InvalidWebhook naming the field (never its value).
 */
final class WebhookFields
{
    private const string RFC3339_WITH_FRACTION = 'Y-m-d\TH:i:s.uP';
    /** 9999-12-31T23:59:59Z: the last second with a four-digit year. */
    private const int MAX_UNIX_TIME = 253_402_300_799;

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidWebhook
     */
    public static function object(string $json, string $name = 'body'): array
    {
        return self::fields(json_decode($json, true), $name);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws InvalidWebhook
     */
    public static function nested(array $data, string $field): array
    {
        return self::fields($data[$field] ?? null, $field);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidWebhook
     */
    public static function string(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        return \is_string($value) ? $value : throw InvalidWebhook::body($field);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidWebhook
     */
    public static function eventId(array $data, string $field): ProviderEventId
    {
        try {
            return ProviderEventId::fromString(self::string($data, $field));
        } catch (\InvalidArgumentException) {
            throw InvalidWebhook::body($field);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidWebhook
     */
    public static function providerPaymentId(array $data, string $field): ProviderPaymentId
    {
        try {
            return ProviderPaymentId::fromString(self::string($data, $field));
        } catch (\InvalidArgumentException) {
            throw InvalidWebhook::body($field);
        }
    }

    /**
     * A positive amount in minor units of a currency the shop takes.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidWebhook
     */
    public static function amount(?int $minorUnits, array $data, string $field, string $currencyField): Amount
    {
        $currency = Currency::tryFrom(self::string($data, $currencyField)) ?? throw InvalidWebhook::body($currencyField);
        try {
            return Amount::of($minorUnits ?? throw InvalidWebhook::body($field), $currency);
        } catch (\InvalidArgumentException) {
            throw InvalidWebhook::body($field);
        }
    }

    /**
     * A time as RFC 3339 text with a zone, with or without a fraction of a second.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidWebhook
     */
    public static function rfc3339(array $data, string $field): \DateTimeImmutable
    {
        $value = self::string($data, $field);
        foreach ([\DATE_RFC3339, self::RFC3339_WITH_FRACTION] as $format) {
            $time = \DateTimeImmutable::createFromFormat($format, $value);
            $problems = \DateTimeImmutable::getLastErrors();
            // A date that does not exist ("2026-02-30") is parsed with a warning and rolled over; refuse it.
            if (false !== $time && (false === $problems || (0 === $problems['warning_count'] && 0 === $problems['error_count']))) {
                return $time;
            }
        }

        throw InvalidWebhook::body($field);
    }

    /**
     * A time as positive Unix seconds, up to 9999-12-31T23:59:59Z.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidWebhook
     */
    public static function unixTime(array $data, string $field): \DateTimeImmutable
    {
        $value = $data[$field] ?? null;
        if (!\is_int($value) || $value < 1 || $value > self::MAX_UNIX_TIME) {
            throw InvalidWebhook::body($field);
        }

        return new \DateTimeImmutable('@'.$value);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidWebhook
     */
    private static function fields(mixed $value, string $name): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw InvalidWebhook::body($name);
        }
        $fields = [];
        foreach ($value as $key => $item) {
            $fields[(string) $key] = $item;
        }

        return $fields;
    }
}
