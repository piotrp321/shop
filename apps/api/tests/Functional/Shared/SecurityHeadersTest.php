<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testSuccessfulResponseIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testNotFoundIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testMethodNotAllowedIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->request('POST', '/health');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testUnauthorizedIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/products');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testServerErrorIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/_test/runtime-error');

        self::assertResponseStatusCodeSame(500);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testCorsPreflightIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/v1/anything', server: [
            'HTTP_ORIGIN' => (string) getenv('ADMIN_URL'),
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testRateLimitedResponseIsNotSniffed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        // `when@test` allows 10 order placements per address; a request refused by the validation counts too.
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $client->request('POST', '/api/v1/orders', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k-'.$attempt], content: '{}');
        }
        $client->request('POST', '/api/v1/orders', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k-11'], content: '{}');

        self::assertResponseStatusCodeSame(429);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }
}
