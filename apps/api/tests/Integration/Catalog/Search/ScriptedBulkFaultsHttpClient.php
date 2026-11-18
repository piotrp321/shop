<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 decorator that injects faults into the bulk requests, in the order of a script, and passes everything
 * else (and every bulk request after the script) to the real Elasticsearch.
 *
 * A script entry is one of:
 * - 'unavailable': the whole request is answered with 503 and never reaches Elasticsearch;
 * - 'timeout': a network error, the request never reaches Elasticsearch;
 * - ['transient' => positions, 'permanent' => positions]: the items at these positions of the request are answered
 *   with 429 (es_rejected_execution_exception) or 400 (mapper_parsing_exception) and are not sent to Elasticsearch,
 *   the other items are.
 */
final class ScriptedBulkFaultsHttpClient implements ClientInterface
{
    /** @var list<int> the number of items sent to Elasticsearch by every bulk request */
    public array $forwardedItems = [];
    public int $bulkRequests = 0;
    /** @var (\Closure(int): void)|null runs before every bulk request is handled, with its number (from 1) */
    public ?\Closure $onBulk = null;

    /**
     * @param list<'unavailable'|'timeout'|array{transient?: list<int>, permanent?: list<int>}> $script
     */
    public function __construct(private readonly ClientInterface $inner, private array $script)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ('POST' !== $request->getMethod() || !str_ends_with($request->getUri()->getPath(), '/_bulk')) {
            return $this->inner->sendRequest($request);
        }

        $body = (string) $request->getBody();
        $request->getBody()->rewind();
        ++$this->bulkRequests;
        if (null !== $this->onBulk) {
            ($this->onBulk)($this->bulkRequests);
        }
        $fault = array_shift($this->script);

        return match (true) {
            null === $fault => $this->forward($request, substr_count($body, "\n") >> 1),
            'unavailable' === $fault => $this->json(503, ['error' => ['type' => 'service_unavailable', 'reason' => 'overloaded'], 'status' => 503]),
            'timeout' === $fault => throw self::networkError($request),
            default => $this->withItemFaults($request, $body, $fault),
        };
    }

    /**
     * @param array{transient?: list<int>, permanent?: list<int>} $fault
     */
    private function withItemFaults(RequestInterface $request, string $body, array $fault): ResponseInterface
    {
        $lines = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => '' !== $line));
        $pairs = array_chunk($lines, 2);
        $forwarded = [];
        $faults = [];
        foreach ($pairs as $position => $pair) {
            $status = match (true) {
                \in_array($position, $fault['transient'] ?? [], true) => [429, 'es_rejected_execution_exception'],
                \in_array($position, $fault['permanent'] ?? [], true) => [400, 'mapper_parsing_exception'],
                default => null,
            };
            if (null === $status) {
                $forwarded[] = $pair;
            } else {
                $action = json_decode($pair[0], true, 8, \JSON_THROW_ON_ERROR);
                \assert(\is_array($action) && \is_array($action['index']));
                $faults[$position] = ['index' => [
                    '_index' => $action['index']['_index'],
                    '_id' => $action['index']['_id'],
                    'status' => $status[0],
                    'error' => ['type' => $status[1], 'reason' => 'injected fault'],
                ]];
            }
        }

        $answers = [];
        if ([] !== $forwarded) {
            $response = $this->forward($request, \count($forwarded), implode("\n", array_merge(...$forwarded))."\n");
            $decoded = json_decode((string) $response->getBody(), true, 16, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded) && \is_array($decoded['items']));
            $answers = $decoded['items'];
        }

        $items = [];
        foreach (array_keys($pairs) as $position) {
            $items[] = $faults[$position] ?? array_shift($answers);
        }

        return $this->json(200, ['took' => 1, 'errors' => true, 'items' => $items]);
    }

    private function forward(RequestInterface $request, int $items, ?string $body = null): ResponseInterface
    {
        $this->forwardedItems[] = $items;
        if (null !== $body) {
            $request = $request->withoutHeader('Content-Length')->withBody(Utils::streamFor($body));
        }

        $response = $this->inner->sendRequest($request);
        $content = (string) $response->getBody();

        // The body is read once here, so hand out a fresh stream.
        return $response->withoutHeader('Content-Length')->withBody(Utils::streamFor($content));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function networkError(RequestInterface $request): NetworkExceptionInterface
    {
        return new class($request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('The request timed out.');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }
}
