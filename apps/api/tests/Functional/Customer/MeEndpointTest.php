<?php

declare(strict_types=1);

namespace App\Tests\Functional\Customer;

use App\Tests\Support\Security\TestTokenFactory;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\RSAKey;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MeEndpointTest extends WebTestCase
{
    public function testValidCustomerTokenReturnsTheCustomer(): void
    {
        $client = $this->requestMe(TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['id' => TestTokenFactory::CUSTOMER_ID, 'email' => 'jan.kowalski@example.com', 'name' => 'Jan Kowalski'],
            $this->json($client->getResponse()),
        );
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testResponseMatchesTheMeResponseSchemaOfTheExportedSpecification(): void
    {
        $client = $this->requestMe(TestTokenFactory::customer());

        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/v1/me', $spec['paths']);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas']['MeResponse'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($this->json($client->getResponse())));
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/me');

        $this->assertUnauthorized($client->getResponse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'staff token (other issuer)' => [TestTokenFactory::staff(['admin'])];
        yield 'staff audience' => [TestTokenFactory::customer(['aud' => 'backoffice-api'])];
        yield 'no audience' => [TestTokenFactory::customer(['aud' => null])];
        yield 'id token of the store client' => [TestTokenFactory::customer(['aud' => 'store', 'typ' => 'ID'])];
        yield 'shop audience with backoffice issuer' => [TestTokenFactory::customer(['iss' => TestTokenFactory::issuer('backoffice')])];
        yield 'issuer of the internal URL' => [TestTokenFactory::customer(['iss' => 'http://keycloak:8080/realms/shop'])];
        yield 'unknown realm' => [TestTokenFactory::customer(['iss' => TestTokenFactory::issuer('master')])];
        yield 'expired' => [TestTokenFactory::customer(['iat' => time() - 600, 'exp' => time() - 1])];
        yield 'not yet valid' => [TestTokenFactory::customer(['nbf' => time() + 600])];
        yield 'issued in the future' => [TestTokenFactory::customer(['iat' => time() + 600])];
        yield 'no expiry' => [TestTokenFactory::customer(['exp' => null])];
        yield 'no issued-at' => [TestTokenFactory::customer(['iat' => null])];
        yield 'no issuer' => [TestTokenFactory::customer(['iss' => null])];
        yield 'audience list without the shop audience' => [TestTokenFactory::customer(['aud' => ['account', 'store']])];
        yield 'empty audience list' => [TestTokenFactory::customer(['aud' => []])];
        yield 'no subject' => [TestTokenFactory::customer(['sub' => null])];
        yield 'no email' => [TestTokenFactory::customer(['email' => null])];
        yield 'garbage' => ['abc'];
        yield 'three garbage segments' => ['abc.def.ghi'];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIsUnauthorized(string $token): void
    {
        $this->assertUnauthorized($this->requestMe($token)->getResponse());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function acceptedAudienceLists(): iterable
    {
        yield 'shop audience first' => [['shop-api', 'account']];
        yield 'shop audience last' => [['account', 'shop-api']];
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('acceptedAudienceLists')]
    public function testAudienceListContainingTheShopAudienceIsAccepted(array $audience): void
    {
        $client = $this->requestMe(TestTokenFactory::customer(['aud' => $audience]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(TestTokenFactory::CUSTOMER_ID, $this->json($client->getResponse())['id']);
    }

    /**
     * What Symfony's header extractor recognises: "Bearer" with exact case followed by whitespace and a token that
     * ends the header. Anything else is no token at all, so a protected endpoint answers 401 as for an anonymous call.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function authorizationHeaders(): iterable
    {
        $token = TestTokenFactory::customer();

        yield 'exact scheme' => ['Bearer '.$token, 200];
        yield 'two spaces' => ['Bearer  '.$token, 200];
        yield 'tab separator' => ["Bearer\t".$token, 200];
        yield 'lower case scheme' => ['bearer '.$token, 401];
        yield 'upper case scheme' => ['BEARER '.$token, 401];
        yield 'trailing space' => ['Bearer '.$token.' ', 401];
        yield 'no separator' => ['Bearer'.$token, 401];
        yield 'token only' => [$token, 401];
    }

    #[DataProvider('authorizationHeaders')]
    public function testAuthorizationHeaderSyntaxIsMatchedAsTheExtractorDefines(string $header, int $status): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => $header]);

        self::assertResponseStatusCodeSame($status);
        if (401 === $status) {
            $this->assertUnauthorized($client->getResponse());
        }
    }

    public function testTokenSignedWithAnotherKeyIsUnauthorized(): void
    {
        // Same kid as the trusted key, so only the signature itself can tell them apart.
        $foreign = JWKFactory::createRSAKey(2048, ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-signing-key']);

        $this->assertUnauthorized($this->requestMe(TestTokenFactory::sign(TestTokenFactory::claimsFor('shop', ['email' => 'a@example.com']), $foreign))->getResponse());
    }

    public function testHmacTokenSignedWithThePublicKeyIsUnauthorized(): void
    {
        // Algorithm confusion: HS256 keyed with the public key, which anyone can read from the JWKS.
        $pem = RSAKey::createFromJWK(TestTokenFactory::signingKey()->toPublic())->toPEM();
        $secret = new JWK(['kty' => 'oct', 'k' => rtrim(strtr(base64_encode($pem), '+/', '-_'), '=')]);

        $this->assertUnauthorized($this->requestMe(TestTokenFactory::sign(TestTokenFactory::claimsFor('shop', ['email' => 'a@example.com']), $secret, 'HS256'))->getResponse());
    }

    public function testUnsignedTokenIsUnauthorized(): void
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $token = $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode(TestTokenFactory::claimsFor('shop', ['email' => 'a@example.com'])).'.';

        $this->assertUnauthorized($this->requestMe($token)->getResponse());
    }

    public function testTamperedPayloadIsUnauthorized(): void
    {
        [$header, , $signature] = explode('.', TestTokenFactory::customer());
        $payload = rtrim(strtr(base64_encode(json_encode(TestTokenFactory::claimsFor('shop', ['sub' => 'someone-else', 'email' => 'x@example.com']), \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->assertUnauthorized($this->requestMe($header.'.'.$payload.'.'.$signature)->getResponse());
    }

    public function testUnauthorizedProblemDoesNotLeakTheReason(): void
    {
        $client = $this->requestMe(TestTokenFactory::customer(['exp' => time() - 1, 'iat' => time() - 600]));

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('expired', $body);
        self::assertStringNotContainsStringIgnoringCase('Invalid credentials', $body);
        self::assertStringNotContainsString('exp', $body);
    }

    private function requestMe(string $token): KernelBrowser
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

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
