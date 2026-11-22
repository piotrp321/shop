<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Transport\NodePool\Node;
use Elastic\Transport\NodePool\Resurrect\ResurrectInterface;
use Elastic\Transport\NodePool\Selector\RoundRobin;
use Elastic\Transport\NodePool\SimpleNodePool;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds Elasticsearch clients on symfony/http-client (through Psr18Client, never discovered).
 * One client per response timeout; the client never retries a request.
 */
final readonly class ElasticsearchClientFactory
{
    public function __construct(private string $url, private float $connectTimeout)
    {
    }

    /**
     * @param float $responseTimeout seconds to wait for the response once connected
     */
    public function create(float $responseTimeout): Client
    {
        return $this->createWith($this->httpClient($responseTimeout));
    }

    /**
     * The PSR-18 client {@see create()} uses; exposed so a test can wrap it.
     *
     * @param float $responseTimeout seconds to wait for the response once connected
     */
    public function httpClient(float $responseTimeout): ClientInterface
    {
        $inner = new Psr18Client(HttpClient::create([
            'max_connect_duration' => $this->connectTimeout,
            'timeout' => $responseTimeout,
            'max_duration' => $this->connectTimeout + $responseTimeout,
        ]));

        // DIAG (temporary): log every slow Elasticsearch request to stderr.
        return new class($inner) implements ClientInterface {
            public function __construct(private ClientInterface $inner)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $start = hrtime(true);
                try {
                    $response = $this->inner->sendRequest($request);
                } catch (\Throwable $e) {
                    fwrite(\STDERR, \sprintf("\n[DIAG es] %.3fs %s %s -> %s %s\n", (hrtime(true) - $start) / 1e9, $request->getMethod(), (string) $request->getUri(), $e::class, $e->getMessage()));
                    throw $e;
                }
                $seconds = (hrtime(true) - $start) / 1e9;
                if ($seconds > 0.3) {
                    $body = (string) $response->getBody();
                    $response->getBody()->rewind();
                    fwrite(\STDERR, \sprintf("\n[DIAG es] %.3fs %s %s -> %d %s\n", $seconds, $request->getMethod(), (string) $request->getUri(), $response->getStatusCode(), substr($body, 0, 300)));
                }

                return $response;
            }
        };
    }

    public function createWith(ClientInterface $httpClient): Client
    {
        // There is a single host. The default pool marks a host dead after one network error and never
        // resurrects it, which would make every later request of a long-running worker fail.
        $nodePool = new SimpleNodePool(new RoundRobin(), new class implements ResurrectInterface {
            public function ping(Node $node): bool
            {
                return true;
            }
        });

        $client = ClientBuilder::create()
            ->setHosts([$this->url])
            ->setHttpClient($httpClient)
            ->setNodePool($nodePool)
            ->build();
        // ClientBuilder treats 0 retries as "not set" and defaults to one retry per host; set it on the transport.
        $client->getTransport()->setRetries(0);

        return $client;
    }
}
