<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the `test_oidc` kernel, whose firewalls fetch the discovery document and JWKS like dev and prod do, from
 * KEYCLOAK_INTERNAL_URL (set per test). The keys are cached in memory only, so every test fetches them anew.
 *
 * A key source that cannot be read is an outage of the API (503), not a bad token: clients must not drop
 * their sessions because Keycloak is down. A token that the reachable keys reject is still a 401.
 */
final class KeycloakOutageTest extends WebTestCase
{
    private const string ENV = 'KEYCLOAK_INTERNAL_URL';

    private mixed $previousEnv = null;
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV[self::ENV] ?? null;
        $this->previousServer = $_SERVER[self::ENV] ?? null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restore($_ENV, $this->previousEnv);
        $this->restore($_SERVER, $this->previousServer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenKeySources(): iterable
    {
        // Nothing listens on port 1: the connection is refused at once.
        yield 'unreachable' => ['http://127.0.0.1:1'];
        // Keycloak answers, but with an error status instead of the discovery document.
        yield 'error response' => ['http://keycloak:8080/no-such-path'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function requestsWithTokens(): iterable
    {
        foreach (self::brokenKeySources() as $name => [$url]) {
            yield "customer endpoint, key source $name" => [$url, '/api/v1/me', TestTokenFactory::customer()];
            yield "staff endpoint, key source $name" => [$url, '/api/v1/admin/me', TestTokenFactory::staff(['admin'])];
            yield "public endpoint, key source $name" => [$url, '/api/v1/categories', 'garbage'];
        }
    }

    #[DataProvider('requestsWithTokens')]
    public function testUnreadableKeySourceIsServiceUnavailable(string $keySource, string $path, string $token): void
    {
        $response = $this->request($keySource, $path, $token)->getResponse();

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503], $this->json($response));
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('127.0.0.1', $body);
        self::assertStringNotContainsString('keycloak', $body);
    }

    public function testMissingTokenDoesNotNeedTheKeysAndIsUnauthorized(): void
    {
        $response = $this->request('http://127.0.0.1:1', '/api/v1/me', null)->getResponse();

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tokensRejectedByTheRealKeys(): iterable
    {
        // Valid claims, but signed with the test key, which the real Keycloak never published.
        yield 'customer token with a foreign signature' => ['/api/v1/me', TestTokenFactory::customer()];
        yield 'staff token with a foreign signature' => ['/api/v1/admin/me', TestTokenFactory::staff(['admin'])];
        yield 'garbage on the customer endpoint' => ['/api/v1/me', 'garbage'];
        yield 'garbage on a public endpoint' => ['/api/v1/categories', 'garbage'];
    }

    #[DataProvider('tokensRejectedByTheRealKeys')]
    public function testInvalidTokenWithReachableKeysIsUnauthorized(string $path, string $token): void
    {
        $response = $this->request('http://keycloak:8080', $path, $token)->getResponse();

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame('Authentication is required.', $this->json($response)['detail']);
    }

    private function request(string $keySource, string $path, ?string $token): KernelBrowser
    {
        $_ENV[self::ENV] = $_SERVER[self::ENV] = $keySource;
        // Debug, so the container of this rarely used environment is rebuilt when the configuration changes.
        $client = static::createClient(['environment' => 'test_oidc', 'debug' => true]);
        $client->request('GET', $path, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $client;
    }

    /**
     * @param array<mixed> $variables
     */
    private function restore(array &$variables, mixed $previous): void
    {
        if (null === $previous) {
            unset($variables[self::ENV]);
        } else {
            $variables[self::ENV] = $previous;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
