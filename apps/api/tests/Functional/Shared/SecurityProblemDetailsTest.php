<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security exceptions become problem details with a fixed detail, whether the security ExceptionListener of a
 * firewall handles them (/api/v1/_test, with a customer token) or they fly outside any firewall (/_test).
 * Routes: tests/Support/Security/TestSecurityController.
 */
final class SecurityProblemDetailsTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function thrownSecurityExceptions(): iterable
    {
        foreach (['outside a firewall' => '/_test/security', 'inside the shop firewall' => '/api/v1/_test/security'] as $where => $prefix) {
            yield "authentication exception $where" => [$prefix.'/authentication', 401];
            yield "access denied by the controller $where" => [$prefix.'/access-denied', 403];
            yield "access denied by a voter $where" => [$prefix.'/voter', 403];
        }
        yield 'authentication exception of a handler outside a firewall' => ['/_test/security/handler-authentication', 401];
        // The ExceptionListener of a firewall follows the `previous` chain itself.
        yield 'wrapped authentication exception inside the shop firewall' => ['/api/v1/_test/security/wrapped-authentication', 401];
        yield 'wrapped access denied inside the shop firewall' => ['/api/v1/_test/security/wrapped-access-denied', 403];
    }

    #[DataProvider('thrownSecurityExceptions')]
    public function testSecurityExceptionBecomesProblemWithGenericDetail(string $path, int $status): void
    {
        $response = $this->get($path);

        self::assertSame($status, $response->getStatusCode());
        $problem = $this->problem($response);
        self::assertSame($status, $problem['status']);
        self::assertSame(401 === $status ? 'Authentication is required.' : 'Access is denied.', $problem['detail']);
        self::assertSame(401 === $status ? 'Bearer' : null, $response->headers->get('WWW-Authenticate'));
        $this->assertNothingLeaks($response);
    }

    /**
     * Outside a firewall only the thrown exception decides: a security exception wrapped in a plain one is a 500.
     *
     * @return iterable<string, array{string}>
     */
    public static function wrappedOutsideFirewall(): iterable
    {
        yield 'authentication exception' => ['/_test/security/wrapped-authentication'];
        yield 'access denied' => ['/_test/security/wrapped-access-denied'];
    }

    #[DataProvider('wrappedOutsideFirewall')]
    public function testWrappedSecurityExceptionOutsideFirewallIsAnInternalError(string $path): void
    {
        $response = $this->get($path);

        self::assertSame(500, $response->getStatusCode());
        $problem = $this->problem($response);
        self::assertArrayNotHasKey('detail', $problem);
        self::assertNull($response->headers->get('WWW-Authenticate'));
        $this->assertNothingLeaks($response);
    }

    private function get(string $path): Response
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $server = str_starts_with($path, '/api/v1/') ? ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()] : [];
        $client->request('GET', $path, server: $server);

        return $client->getResponse();
    }

    private function assertNothingLeaks(Response $response): void
    {
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('secret detail', $body);
        self::assertStringNotContainsString('Exception', $body);
        self::assertStringNotContainsString('reached', $body);
        self::assertStringNotContainsString('.php', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(Response $response): array
    {
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);

        /** @var array<string, mixed> $problem */
        return $problem;
    }
}
