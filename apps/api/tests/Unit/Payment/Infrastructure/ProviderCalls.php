<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A MockHttpClient that answers every request with the given responses in turn and records each request
 * (method, URL, normalized headers, body, timeouts).
 */
final class ProviderCalls
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string, timeout: ?float, max_duration: ?float}> */
    public array $requests = [];
    public MockHttpClient $client;

    public function __construct(MockResponse ...$responses)
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $headers = [];
            $normalized = $options['normalized_headers'] ?? [];
            \assert(\is_array($normalized));
            foreach ($normalized as $lines) {
                foreach (\is_array($lines) ? $lines : [] as $line) {
                    $headers[] = \is_string($line) ? $line : '';
                }
            }
            $body = $options['body'] ?? '';
            $seconds = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => \is_string($body) ? $body : '', 'timeout' => $seconds($options['timeout'] ?? null), 'max_duration' => $seconds($options['max_duration'] ?? null)];

            return array_shift($responses) ?? throw new \LogicException('No response left.');
        });
    }

    public static function json(int $status, mixed $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['Content-Type: application/json']]);
    }

    public static function text(int $status, string $body): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status]);
    }

    public static function networkError(): MockResponse
    {
        return new MockResponse('', ['error' => 'Idle timeout reached for "http://api".']);
    }

    /**
     * @return array<mixed>
     */
    public function body(int $index = 0): array
    {
        $decoded = json_decode($this->requests[$index]['body'], true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
