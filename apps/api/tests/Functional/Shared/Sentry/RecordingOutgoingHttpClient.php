<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Sentry;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The transport under the shared `http_client` in the tests (config/services.yaml, below the decorator of Sentry): it
 * keeps the headers of every request that reaches the network as the application sent them. It only passes the
 * requests through, unless a test calls offline(), which answers them without any network.
 */
final class RecordingOutgoingHttpClient implements HttpClientInterface
{
    /** @var list<array<string, true>> the names of the headers of every request, in lower case */
    private array $requests = [];

    private bool $offline = false;

    public function __construct(private readonly HttpClientInterface $inner)
    {
    }

    public function offline(): void
    {
        $this->offline = true;
    }

    /**
     * @return list<list<string>> the names of the headers of every request, in lower case
     */
    public function requests(): array
    {
        return array_map(static fn (array $names): array => array_keys($names), $this->requests);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $names = [];
        foreach ((array) ($options['headers'] ?? []) as $name => $value) {
            $header = \is_string($name) ? $name : explode(':', (string) (\is_scalar($value) ? $value : ''), 2)[0];
            $names[strtolower(trim($header))] = true;
        }
        $this->requests[] = $names;

        return $this->offline ? new MockResponse('', ['http_code' => 200]) : $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = new self($this->inner->withOptions($options));
        $clone->requests = &$this->requests;
        $clone->offline = $this->offline;

        return $clone;
    }
}
