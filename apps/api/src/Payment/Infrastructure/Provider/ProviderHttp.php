<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Provider;

use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Domain\Payment;
use App\Payment\Domain\ProviderPaymentId;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The JSON calls of the adapters: each bounded by 5 s (to the first byte and in total), any transport failure or
 * an answer that is no JSON object is PaymentProviderUnavailable. The messages name the provider and the cause,
 * never a key.
 */
final class ProviderHttp
{
    private const float TIMEOUT_SECONDS = 5.0;

    /**
     * @param array<string, mixed> $options auth and body of the request
     *
     * @return array{int, array<string, mixed>} the status and the JSON object of the answer
     *
     * @throws PaymentProviderUnavailable
     */
    public static function post(HttpClientInterface $client, string $provider, string $url, array $options): array
    {
        try {
            $response = $client->request('POST', $url, [...$options, 'timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw new PaymentProviderUnavailable(\sprintf('%s did not answer: %s', $provider, $failure->getMessage()), previous: $failure);
        }
        $data = json_decode($content, true);
        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            throw self::unexpected($provider, $status);
        }
        $object = [];
        foreach ($data as $name => $value) {
            $object[(string) $name] = $value;
        }

        return [$status, $object];
    }

    public static function unexpected(string $provider, int $status): PaymentProviderUnavailable
    {
        return new PaymentProviderUnavailable(\sprintf('%s gave an unexpected answer (status %d).', $provider, $status));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws PaymentProviderUnavailable
     */
    public static function sessionId(array $data, string $field, string $provider, int $status): ProviderPaymentId
    {
        $value = $data[$field] ?? null;
        try {
            return ProviderPaymentId::fromString(\is_string($value) ? $value : '');
        } catch (\InvalidArgumentException) {
            throw self::unexpected($provider, $status);
        }
    }

    /**
     * An absolute http(s) URL of at most 500 characters (what a payment keeps).
     *
     * @param array<string, mixed> $data
     *
     * @throws PaymentProviderUnavailable
     */
    public static function pageUrl(array $data, string $field, string $provider, int $status): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || \strlen($value) > Payment::MAX_REDIRECT_URL_LENGTH || false === filter_var($value, \FILTER_VALIDATE_URL)
            || !\in_array(parse_url($value, \PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw self::unexpected($provider, $status);
        }

        return $value;
    }
}
