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
        return new Psr18Client(HttpClient::create([
            'max_connect_duration' => $this->connectTimeout,
            'timeout' => $responseTimeout,
            'max_duration' => $this->connectTimeout + $responseTimeout,
        ]));
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
