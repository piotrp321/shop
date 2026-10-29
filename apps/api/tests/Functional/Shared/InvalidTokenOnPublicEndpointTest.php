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
}
