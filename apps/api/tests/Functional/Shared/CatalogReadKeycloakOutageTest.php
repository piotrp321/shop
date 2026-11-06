<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public catalog reads with a token when the keys of its issuer cannot be fetched (the `test_oidc` kernel with
 * KEYCLOAK_INTERNAL_URL where nothing listens): a 503 that no cache may keep, without cache tags.
 */
final class CatalogReadKeycloakOutageTest extends WebTestCase
{
    private const string ENV = 'KEYCLOAK_INTERNAL_URL';

    private mixed $previousEnv = null;
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV[self::ENV] ?? null;
        $this->previousServer = $_SERVER[self::ENV] ?? null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restore($_ENV, $this->previousEnv);
        $this->restore($_SERVER, $this->previousServer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'product list' => ['/api/v1/products'];
        yield 'product details' => ['/api/v1/products/buty-szosowe'];
        yield 'category tree' => ['/api/v1/categories'];
        yield 'product offers' => ['/api/v1/product-offers?productId=0192f0c4-7c1a-7c4e-9a6e-000000000001'];
    }

    #[DataProvider('paths')]
    public function testAnOutageIsNotStored(string $path): void
    {
        $_ENV[self::ENV] = $_SERVER[self::ENV] = 'http://127.0.0.1:1';
        // Debug, so the container of this rarely used environment is rebuilt when the configuration changes.
        $client = static::createClient(['environment' => 'test_oidc', 'debug' => true]);
        $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);
        $response = $client->getResponse();

        self::assertSame(503, $response->getStatusCode());
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
        self::assertFalse($response->headers->has('xkey'));
    }

    /**
     * @param array<mixed> $variables
     */
    private function restore(array &$variables, mixed $previous): void
    {
        if (null === $previous) {
            unset($variables[self::ENV]);
        } else {
            $variables[self::ENV] = $previous;
        }
    }
}
