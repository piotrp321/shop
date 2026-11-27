<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 decorator that records every request, runs a hook before it and passes it on.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var (\Closure(RequestInterface): void)|null runs before every request is passed on */
    public ?\Closure $before = null;

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if (null !== $this->before) {
            ($this->before)($request);
        }

        return $this->inner->sendRequest($request);
    }

    /**
     * @return list<RequestInterface> the alias updates (POST /_aliases)
     */
    public function aliasSwitches(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (RequestInterface $r): bool => 'POST' === $r->getMethod() && '/_aliases' === $r->getUri()->getPath(),
        ));
    }

    /**
     * @return list<array<mixed>> the decoded bodies of the alias updates
     */
    public function aliasRequests(): array
    {
        return array_map(static function (RequestInterface $request): array {
            $body = (string) $request->getBody();
            $request->getBody()->rewind();
            $decoded = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded));

            return $decoded;
        }, $this->aliasSwitches());
    }
}
