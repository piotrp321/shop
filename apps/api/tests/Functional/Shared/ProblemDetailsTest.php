<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProblemDetailsTest extends WebTestCase
{
    public function testUnknownRouteReturnsProblem(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        $problem = $this->problem($client->getResponse());
        self::assertSame(404, $problem['status']);
        self::assertArrayHasKey('type', $problem);
        self::assertArrayHasKey('title', $problem);
    }

    public function testWrongMethodReturns405Problem(): void
    {
        $client = static::createClient();
        $client->request('POST', '/health');

        self::assertResponseStatusCodeSame(405);
        self::assertSame(405, $this->problem($client->getResponse())['status']);
        self::assertStringContainsString('GET', (string) $client->getResponse()->headers->get('Allow'));
    }

    public function testUnhandledExceptionReturns500WithoutDetail(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/_test/runtime-error');

        self::assertResponseStatusCodeSame(500);
        $body = (string) $client->getResponse()->getContent();
        $problem = $this->problem($client->getResponse());
        self::assertSame(500, $problem['status']);
        self::assertArrayNotHasKey('detail', $problem);
        self::assertStringNotContainsString('secret detail', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('.php', $body);
    }

    public function testServiceUnavailableReturns503(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/unavailable');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(503, $this->problem($client->getResponse())['status']);
    }

    public function testValidationFailureReturns400WithViolations(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/invalid');

        self::assertResponseStatusCodeSame(400);
        $problem = $this->problem($client->getResponse());
        self::assertSame(400, $problem['status']);
        self::assertSame([['propertyPath' => 'name', 'title' => 'Name is required.']], $problem['violations']);
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(\Symfony\Component\HttpFoundation\Response $response): array
    {
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);

        /** @var array<string, mixed> $problem */
        return $problem;
    }
}
