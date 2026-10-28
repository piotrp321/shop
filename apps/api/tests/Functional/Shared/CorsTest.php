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
