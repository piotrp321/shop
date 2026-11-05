<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The order endpoints when the keys of the token issuer cannot be fetched: the `test_oidc` kernel fetches the
 * discovery document and JWKS from KEYCLOAK_INTERNAL_URL, set here to an address where nothing listens. A token that
 * cannot be checked is an outage (503, `private, no-store`), never a 401 that would end the session of the store.
 */
final class OrderKeycloakOutageTest extends WebTestCase
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
        yield 'customer order' => ['/api/v1/me/orders'];
        yield 'guest order with a token' => ['/api/v1/orders'];
    }

    #[DataProvider('paths')]
    public function testUnreadableKeysOfTheIssuerAreServiceUnavailable(string $path): void
    {
        $_ENV[self::ENV] = $_SERVER[self::ENV] = 'http://127.0.0.1:1';
        // Debug, so the container of this rarely used environment is rebuilt when the configuration changes.
        $client = static::createClient(['environment' => 'test_oidc', 'debug' => true]);
        $client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer(),
            'HTTP_IDEMPOTENCY_KEY' => 'outage-key',
        ], content: '{"cartId": "0192f0c4-7c1a-7c4e-9a6e-000000000001", "email": "anna@example.com", "fullName": "Anna Nowak", "phone": "600100200", "street": "Długa 5", "postalCode": "00-950", "city": "Warszawa", "expectedTotalGross": 100}');
        $response = $client->getResponse();

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($response->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
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
