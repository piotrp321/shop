<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Unavailable marker: a project exception about a dependency that cannot be reached is a 503 like any server
 * error, with a generic body and without `detail`, whatever its message says.
 */
final class UnavailableProblemTest extends WebTestCase
{
    public function testAnUnavailableExceptionIsA503WithoutDetail(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/project-unavailable');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = (string) $client->getResponse()->getContent();
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503],
            json_decode($body, true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('provider is down', $body);
    }

    public function testAnUnavailableExceptionThrownByAHandlerIsUnwrappedTo503(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_test/handler-unavailable');

        self::assertResponseStatusCodeSame(503);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(503, $problem['status']);
        self::assertArrayNotHasKey('detail', $problem);
    }
}
