<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 decorator that records every request to the real Elasticsearch and can answer one search of a `_msearch`
 * with an error, as Elasticsearch answers a part that failed: the other parts keep their real answers.
 */
final class RecordingSearchHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, body: string}> */
    public array $requests = [];
    private int $multiSearches = 0;

    /**
     * @param array{request: int, part: int, status: int, type: string}|null $fault the part (from 0) of the multi search
     *                                                                              (from 1) answered with the error
     */
    public function __construct(private readonly ClientInterface $inner, private readonly ?array $fault = null)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $request->getBody()->rewind();
        $this->requests[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'body' => $body];

        $response = $this->inner->sendRequest($request);
        if (!str_ends_with($request->getUri()->getPath(), '/_msearch')) {
            return $response;
        }
        ++$this->multiSearches;
        if (null === $this->fault || $this->fault['request'] !== $this->multiSearches) {
            return $response;
        }

        $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded) && \is_array($decoded['responses'] ?? null));
        $decoded['responses'][$this->fault['part']] = [
            'error' => ['type' => $this->fault['type'], 'reason' => 'injected fault'],
            'status' => $this->fault['status'],
        ];

        return $response->withoutHeader('Content-Length')->withBody(Utils::streamFor(json_encode($decoded, \JSON_THROW_ON_ERROR)));
    }

    /**
     * The searches of every multi search, in order: the body of each search (the header lines left out).
     *
     * @return list<list<array<string, mixed>>>
     */
    public function multiSearches(): array
    {
        $searches = [];
        foreach ($this->requests as $request) {
            if (!str_ends_with($request['path'], '/_msearch')) {
                continue;
            }
            $lines = array_values(array_filter(explode("\n", $request['body']), static fn (string $line): bool => '' !== $line));
            $bodies = [];
            foreach (array_chunk($lines, 2) as [, $body]) {
                $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
                \assert(\is_array($decoded));
                /** @var array<string, mixed> $decoded */
                $bodies[] = $decoded;
            }
            $searches[] = $bodies;
        }

        return $searches;
    }

    /**
     * @return list<string> "<method> <path>" of every request
     */
    public function paths(): array
    {
        return array_map(static fn (array $request): string => $request['method'].' '.$request['path'], $this->requests);
    }
}
