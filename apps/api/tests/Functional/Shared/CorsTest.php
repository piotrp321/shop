<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CorsTest extends WebTestCase
{
    public function testAdminOriginIsAllowed(): void
    {
        $admin = $this->env('ADMIN_URL');
        $response = $this->preflight('/api/v1/anything', $admin);

        self::assertSame($admin, $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * The panel continues its trace in the API with sentry-trace and baggage (spec: docs/features/sentry/spec.md, D7).
     */
    public function testTheAdminOriginMaySendTheTracingHeadersOfSentry(): void
    {
        $admin = $this->env('ADMIN_URL');
        $client = static::createClient();
        $client->request('OPTIONS', '/api/v1/admin/orders', server: [
            'HTTP_ORIGIN' => $admin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, sentry-trace, baggage',
        ]);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($admin, $response->headers->get('Access-Control-Allow-Origin'));
        $allowed = array_map(trim(...), explode(',', strtolower((string) $response->headers->get('Access-Control-Allow-Headers'))));
        self::assertContains('sentry-trace', $allowed);
        self::assertContains('baggage', $allowed);
        self::assertContains('authorization', $allowed);
    }

    public function testStoreOriginIsNotAllowed(): void
    {
        self::assertNull($this->preflight('/api/v1/anything', $this->env('STORE_URL'))->headers->get('Access-Control-Allow-Origin'));
    }

    public function testForeignOriginIsNotAllowed(): void
    {
        self::assertNull($this->preflight('/api/v1/anything', 'https://evil.example')->headers->get('Access-Control-Allow-Origin'));
    }

    public function testAdminOriginOutsideApiPrefixIsNotAllowed(): void
    {
        self::assertNull($this->preflight('/health', $this->env('ADMIN_URL'))->headers->get('Access-Control-Allow-Origin'));
    }

    private function preflight(string $path, string $origin): \Symfony\Component\HttpFoundation\Response
    {
        $client = static::createClient();
        $client->request('OPTIONS', $path, server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        return $client->getResponse();
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, $name.' must be set in the test environment.');
        self::assertNotSame('', $value);

        return $value;
    }
}
