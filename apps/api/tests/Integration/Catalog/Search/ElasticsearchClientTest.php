<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Catalog\Infrastructure\Search\ElasticsearchErrorClassifier;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The client stack (Psr18Client, elastic/transport, the error classifier) without a running Elasticsearch:
 * an unreachable host and canned responses.
 */
final class ElasticsearchClientTest extends TestCase
{
    public function testUnreachableHostGivesSearchUnavailableWithinTwoSeconds(): void
    {
        // TEST-NET-1 (RFC 5737): never routed, so the connection attempt hangs until the connect timeout.
        $client = new ElasticsearchClientFactory('http://192.0.2.1:9200', 1.0)->create(2.0);

        $start = microtime(true);
        try {
            ElasticsearchErrorClassifier::request('ping', static fn () => $client->info());
            self::fail('No exception for an unreachable host.');
        } catch (SearchUnavailable) {
        }

        self::assertLessThan(2.0, microtime(true) - $start);
    }

    /**
     * @return iterable<string, array{int, string, class-string<\Throwable>}>
     */
    public static function errorResponses(): iterable
    {
        yield '429 too many requests' => [429, 'es_rejected_execution_exception', SearchUnavailable::class];
        yield '503 unavailable' => [503, 'no_shard_available_action_exception', SearchUnavailable::class];
        yield '502 bad gateway' => [502, 'bad_gateway', SearchUnavailable::class];
        yield '504 gateway timeout' => [504, 'gateway_timeout', SearchUnavailable::class];
        yield 'rejected execution with another status' => [500, 'es_rejected_execution_exception', SearchUnavailable::class];
        yield '400 bad request' => [400, 'illegal_argument_exception', SearchIndexError::class];
        yield '404 not found' => [404, 'index_not_found_exception', SearchIndexError::class];
        yield '500 internal error' => [500, 'null_pointer_exception', SearchIndexError::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('errorResponses')]
    public function testClassifiesErrorResponses(int $status, string $errorType, string $expected): void
    {
        $client = $this->clientAnswering(self::errorResponse($status, $errorType));

        $this->expectException($expected);

        ElasticsearchErrorClassifier::request('search', static fn () => $client->search(['index' => 'test_products']));
    }

    public function testAcceptedErrorStatusIsReturnedAsAResponse(): void
    {
        $client = $this->clientAnswering(self::errorResponse(404, 'aliases_not_found_exception'));

        $response = ElasticsearchErrorClassifier::request('read alias', static fn () => $client->indices()->getAlias(['name' => 'test_products']), [404]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testNetworkErrorIsNotRetriedAndDoesNotMarkTheOnlyNodeDead(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection reset by peer']),
            self::okResponse(['cluster_name' => 'test']),
        ]);
        $client = new ElasticsearchClientFactory('http://elasticsearch:9200', 1.0)->createWith(new Psr18Client($http));

        try {
            ElasticsearchErrorClassifier::request('ping', static fn () => $client->info());
            self::fail('No exception for a network error.');
        } catch (SearchUnavailable) {
        }
        self::assertSame(1, $http->getRequestsCount(), 'The client retried the request.');

        $response = ElasticsearchErrorClassifier::request('ping', static fn () => $client->info());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $http->getRequestsCount());
    }

    private function clientAnswering(MockResponse $response): Client
    {
        return new ElasticsearchClientFactory('http://elasticsearch:9200', 1.0)->createWith(new Psr18Client(new MockHttpClient($response)));
    }

    private static function errorResponse(int $status, string $type): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => ['type' => $type, 'reason' => 'canned'], 'status' => $status], \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json', 'x-elastic-product' => 'Elasticsearch']],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function okResponse(array $body): MockResponse
    {
        return new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json', 'x-elastic-product' => 'Elasticsearch']],
        );
    }
}
