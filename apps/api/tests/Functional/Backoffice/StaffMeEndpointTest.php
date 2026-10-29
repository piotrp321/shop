<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Tests\Support\Security\TestTokenFactory;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class StaffMeEndpointTest extends WebTestCase
{
    public function testValidStaffTokenReturnsTheStaffMember(): void
    {
        $client = $this->requestStaffMe(TestTokenFactory::staff(['admin', 'offline_access', 'uma_authorization']));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['id' => TestTokenFactory::STAFF_ID, 'username' => 'admin', 'roles' => ['admin']],
            $this->json($client->getResponse()),
        );
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function roles(): iterable
    {
        yield 'one role' => [['support'], ['support']];
        yield 'several roles, unknown ones dropped' => [['marketing', 'default-roles-backoffice', 'warehouse'], ['warehouse', 'marketing']];
        yield 'no known role' => [['offline_access'], []];
        yield 'no roles' => [[], []];
        yield 'case matters' => [['Admin', 'ADMIN'], []];
    }

    /**
     * @param list<string> $tokenRoles
     * @param list<string> $expected
     */
    #[DataProvider('roles')]
    public function testRolesAreTheKnownStaffRolesOfTheToken(array $tokenRoles, array $expected): void
    {
        $client = $this->requestStaffMe(TestTokenFactory::staff($tokenRoles));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($expected, $this->json($client->getResponse())['roles']);
    }

    public function testResponseMatchesTheStaffMeResponseSchemaOfTheExportedSpecification(): void
    {
        $client = $this->requestStaffMe(TestTokenFactory::staff(['admin']));

        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/v1/admin/me', $spec['paths']);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas']['StaffMeResponse'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($this->json($client->getResponse())));
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/me');

        $this->assertUnauthorized($client->getResponse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'customer token (other issuer)' => [TestTokenFactory::customer()];
        yield 'customer audience' => [TestTokenFactory::staff(['admin'], ['aud' => 'shop-api'])];
        yield 'admin client id as audience' => [TestTokenFactory::staff(['admin'], ['aud' => 'admin'])];
        yield 'backoffice audience with shop issuer' => [TestTokenFactory::staff(['admin'], ['iss' => TestTokenFactory::issuer('shop')])];
        yield 'expired' => [TestTokenFactory::staff(['admin'], ['iat' => time() - 600, 'exp' => time() - 1])];
        yield 'not yet valid' => [TestTokenFactory::staff(['admin'], ['nbf' => time() + 600])];
        yield 'no username' => [TestTokenFactory::staff(['admin'], ['preferred_username' => null])];
        yield 'no subject' => [TestTokenFactory::staff(['admin'], ['sub' => null])];
        yield 'garbage' => ['abc'];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIsUnauthorized(string $token): void
    {
        $this->assertUnauthorized($this->requestStaffMe($token)->getResponse());
    }

    public function testTokenSignedWithAnotherKeyIsUnauthorized(): void
    {
        $foreign = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-signing-key']);
        $claims = TestTokenFactory::claimsFor('backoffice', ['preferred_username' => 'admin', 'realm_access' => ['roles' => ['admin']]]);

        $this->assertUnauthorized($this->requestStaffMe(TestTokenFactory::sign($claims, $foreign))->getResponse());
    }

    /**
     * Paths that reach the staff controller, or nothing at all, must never accept a customer token.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function pathVariants(): iterable
    {
        yield 'percent-encoded segment' => ['/api/v1/%61dmin/me', 401];
        yield 'upper case' => ['/api/v1/ADMIN/me', 404];
        yield 'double slash' => ['/api/v1//admin/me', 404];
        yield 'dot segment' => ['/api/v1/x/../admin/me', 404];
    }

    #[DataProvider('pathVariants')]
    public function testCustomerTokenNeverReachesTheStaffEndpoint(string $path, int $status): void
    {
        $client = static::createClient();
        $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);

        self::assertResponseStatusCodeSame($status);
        self::assertStringNotContainsString(TestTokenFactory::CUSTOMER_ID, (string) $client->getResponse()->getContent());
    }

    private function requestStaffMe(string $token): KernelBrowser
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $client;
    }

    private function assertUnauthorized(Response $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'detail' => 'Authentication is required.'],
            $this->json($response),
        );
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
