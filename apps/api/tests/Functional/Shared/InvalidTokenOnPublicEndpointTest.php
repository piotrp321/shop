<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Public endpoints need no token, but a token that is sent must be valid: a client never gets an anonymous answer
 * for a broken session.
 */
final class InvalidTokenOnPublicEndpointTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'garbage on the product list' => ['/api/v1/products', 'garbage'];
        yield 'garbage on the category tree' => ['/api/v1/categories', 'garbage'];
        yield 'staff token on the category tree' => ['/api/v1/categories', TestTokenFactory::staff(['admin'])];
        yield 'expired customer token on the category tree' => ['/api/v1/categories', TestTokenFactory::customer(['iat' => time() - 600, 'exp' => time() - 1])];
        yield 'customer token without issued-at' => ['/api/v1/categories', TestTokenFactory::customer(['iat' => null])];
        yield 'customer token without issuer' => ['/api/v1/categories', TestTokenFactory::customer(['iss' => null])];
        yield 'audience list without the shop audience' => ['/api/v1/products', TestTokenFactory::customer(['aud' => ['account', 'store']])];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIsUnauthorized(string $path, string $token): void
    {
        $client = static::createClient();
        $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer', $client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringNotContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testValidCustomerTokenIsAccepted(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/categories', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testNoTokenIsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/categories');

        self::assertResponseStatusCodeSame(200);
    }

    public function testOtherAuthorizationSchemeIsIgnored(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/categories', server: ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']);

        self::assertResponseStatusCodeSame(200);
    }

    public function testAudienceListContainingTheShopAudienceIsAccepted(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/categories', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer(['aud' => ['shop-api', 'account']])]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * The extractor recognises "Bearer" with exact case, whitespace and a token that ends the header. A header it does
     * not recognise is no token, so the public endpoint answers as to an anonymous client, even though the token in
     * it is expired. A recognised one is validated and rejected.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function authorizationHeadersWithExpiredToken(): iterable
    {
        $token = TestTokenFactory::customer(['iat' => time() - 600, 'exp' => time() - 1]);

        yield 'exact scheme' => ['Bearer '.$token, 401];
        yield 'two spaces' => ['Bearer  '.$token, 401];
        yield 'tab separator' => ["Bearer\t".$token, 401];
        yield 'lower case scheme' => ['bearer '.$token, 200];
        yield 'trailing space' => ['Bearer '.$token.' ', 200];
        yield 'token only' => [$token, 200];
    }

    #[DataProvider('authorizationHeadersWithExpiredToken')]
    public function testAuthorizationHeaderSyntaxDecidesWhetherAnExpiredTokenIsEvaluated(string $header, int $status): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/products', server: ['HTTP_AUTHORIZATION' => $header]);

        self::assertResponseStatusCodeSame($status);
    }
}
