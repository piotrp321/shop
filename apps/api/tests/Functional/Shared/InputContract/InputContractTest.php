<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The contract of the answers to bad input outside the fields of JSON bodies (spec:
 * docs/features/api-input-contract/spec.md, "Test kontraktu wejścia").
 *
 * The table of rows (one file of rows per module that owns the operations) runs every row twice, as two tests: the
 * valid value is accepted with a 2xx, the invalid value is a 400 with exactly the expected violations. Each test
 * builds the state of the row with its fixture from nothing (the transaction of the test is rolled back after it), and
 * `{idempotencyKey}` is a new key in every request, so the positive check never uses up a resource, a version or a key
 * before the negative one.
 *
 * Section A takes its operations from the router (405, 401, 404) and from the OpenAPI documents (the operations with
 * a JSON body: 415, 400).
 */
final class InputContractTest extends WebTestCase
{
    private const string BAD_REQUEST = 'Żądanie jest nieprawidłowe.';

    /** Methods tried, in this order, on a path that does not serve them. */
    private const array METHODS = ['PATCH', 'PUT', 'DELETE', 'POST', 'GET'];

    /** The webhook of the providers reads a signed body of its own format: a 400, never a 415. */
    private const string WEBHOOK = '/api/v1/payments/webhooks/{provider}';

    /** Operations that look their resource up before they read the body: the fixture of their existing resource. */
    private const array EXISTING_RESOURCE = [
        'PUT /api/v1/admin/delivery-methods/{id}' => 'deliveryMethod',
        'PUT /api/v1/admin/discount-codes/{id}' => 'discountCode',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return iterable<string, array{InputCase}>
     */
    public static function rows(): iterable
    {
        foreach ([InventoryCases::all(), PricingCases::all()] as $rows) {
            foreach ($rows as $row) {
                yield $row->name() => [$row];
            }
        }
    }

    #[DataProvider('rows')]
    public function testTheValidValueOfARowIsAccepted(InputCase $row): void
    {
        $response = $this->send($row, $row->valid);

        self::assertGreaterThanOrEqual(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
    }

    #[DataProvider('rows')]
    public function testTheInvalidValueOfARowGivesExactlyItsViolations(InputCase $row): void
    {
        $response = $this->send($row, $row->invalid);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $problem = $this->problem($response);
        self::assertArrayNotHasKey('detail', $problem);
        self::assertIsArray($problem['violations'] ?? null);
        $expected = array_map(static fn (array $violation): array => ['propertyPath' => $violation[0], 'title' => $violation[1]], $row->violations);
        $actual = $problem['violations'];
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }

    public function testAMethodAPathDoesNotServeIs405(): void
    {
        $methods = [];
        foreach ($this->routes() as [$method, $path]) {
            $methods[$path][] = $method;
        }
        self::assertGreaterThanOrEqual(40, \count($methods));

        $failures = [];
        foreach ($methods as $path => $served) {
            $method = current(array_diff(self::METHODS, $served));
            self::assertIsString($method, $path);
            $response = $this->request($method, self::url($path), $this->tokenFor($path));
            $failures[] = $this->genericFailure("$method $path", $response, 405, 'Metoda nie jest dozwolona.');
            if ('' === (string) $response->headers->get('Allow')) {
                $failures[] = "$method $path: no Allow header";
            }
        }

        self::assertSame([], array_values(array_filter($failures)));
    }

    public function testEveryOperationThatNeedsATokenIs401WithoutOne(): void
    {
        $failures = [];
        $protected = [];
        foreach ($this->routes() as [$method, $path]) {
            $url = self::url($path);
            if (!$this->needsAToken($method, $url)) {
                continue;
            }
            $protected[] = "$method $path";
            $failures[] = $this->genericFailure("$method $path", $this->request($method, $url, null), 401, 'Wymagane jest uwierzytelnienie.');
        }

        foreach (['GET /api/v1/admin/me', 'POST /api/v1/me/orders', 'GET /api/v1/me/cart', 'DELETE /api/v1/admin/promotions/{id}'] as $known) {
            self::assertContains($known, $protected);
        }
        self::assertNotContains('GET /api/v1/products', $protected);
        self::assertSame([], array_values(array_filter($failures)));
    }

    /**
     * Not valid UTF-8, so no route matches it: the router answers before the firewall and the controller.
     */
    public function testAPathIdentifierInAFormatTheRouteDoesNotTakeIs404(): void
    {
        $failures = [];
        $operations = 0;
        foreach ($this->routes() as [$method, $path]) {
            if (!str_contains($path, '{')) {
                continue;
            }
            ++$operations;
            $url = (string) preg_replace('/\{[^}]+\}/', '%FF', $path);
            $failures[] = $this->genericFailure("$method $path", $this->request($method, $url, $this->tokenFor($path)), 404, 'Nie znaleziono zasobu.');
        }

        self::assertGreaterThanOrEqual(30, $operations);
        self::assertSame([], array_values(array_filter($failures)));
    }

    public function testABodyOfAnotherContentTypeIs415(): void
    {
        $failures = [];
        foreach ($this->jsonBodyOperations() as [$method, $path]) {
            if (self::WEBHOOK === $path) {
                continue;
            }
            $response = $this->request($method, $this->existingUrl($method, $path), $this->tokenFor($path), '{}', 'text/plain', self::newIdempotencyKey());
            $failures[] = $this->genericFailure("$method $path", $response, 415, 'Nieobsługiwany typ treści.');
        }

        self::assertSame([], array_values(array_filter($failures)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNoJson(): iterable
    {
        yield 'broken JSON' => ['{"name":'];
        yield 'invalid UTF-8' => ["{\"name\":\"\xFF\"}"];
        yield 'nested deeper than the limit of the decoder' => [str_repeat('[', 600).str_repeat(']', 600)];
    }

    #[DataProvider('bodiesThatAreNoJson')]
    public function testABodyThatIsNoJsonIs400(string $body): void
    {
        $failures = [];
        $operations = 0;
        foreach ($this->jsonBodyOperations() as [$method, $path]) {
            ++$operations;
            $response = $this->request($method, $this->existingUrl($method, $path), $this->tokenFor($path), $body, server: self::newIdempotencyKey());
            $failures[] = $this->genericFailure("$method $path", $response, 400, self::BAD_REQUEST);
            if (str_contains((string) $response->getContent(), '"violations"')) {
                $failures[] = "$method $path: violations";
            }
        }

        self::assertGreaterThanOrEqual(25, $operations);
        self::assertSame([], array_values(array_filter($failures)));
    }

    private function send(InputCase $row, ?string $value): Response
    {
        $placeholders = $this->placeholders($row->fixture) + ['{idempotencyKey}' => Uuid::v4()->toRfc4122()];

        $url = strtr($row->path, $placeholders);
        $server = [];
        if ('query' === $row->location && null !== $value && '' !== $value) {
            $url .= '?'.strtr($value, $placeholders);
        }
        if ('header' === $row->location && null !== $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $row->input))] = strtr($value, $placeholders);
        }
        $token = match ($row->token) {
            InputCase::STAFF => TestTokenFactory::staff(['admin']),
            InputCase::CUSTOMER => TestTokenFactory::customer(),
            null => null,
        };

        return $this->request($row->method, $url, $token, null === $row->body ? null : strtr($row->body, $placeholders), server: $server);
    }

    /**
     * Each method of each route of the API.
     *
     * @return list<array{string, string}> the method and the path of the route
     */
    private function routes(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            // The routes of the API, not the ones tests/Support adds in the test environment.
            if (str_starts_with($route->getPath(), '/api/v1/') && !str_starts_with($route->getPath(), '/api/v1/_test/')) {
                foreach ($route->getMethods() as $method) {
                    $routes[] = [$method, $route->getPath()];
                }
            }
        }

        return $routes;
    }

    /**
     * Each operation of the OpenAPI documents whose request body is JSON.
     *
     * @return list<array{string, string}> the method and the path
     */
    private function jsonBodyOperations(): array
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        $operations = [];
        foreach (['openapi.json', 'openapi-admin.json'] as $file) {
            $document = json_decode((string) file_get_contents($projectDir.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($document);
            self::assertIsArray($document['paths']);
            foreach ($document['paths'] as $path => $item) {
                self::assertIsArray($item);
                foreach ($item as $method => $operation) {
                    $content = \is_array($operation) && \is_array($operation['requestBody'] ?? null) ? $operation['requestBody']['content'] ?? null : null;
                    if (\is_array($content) && \array_key_exists('application/json', $content)) {
                        $operations[] = [strtoupper((string) $method), (string) $path];
                    }
                }
            }
        }

        return $operations;
    }

    /**
     * Whether security.yaml asks for more than public access on the URL: the access map of the firewall.
     */
    private function needsAToken(string $method, string $url): bool
    {
        $accessMap = self::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);
        [$attributes] = $accessMap->getPatterns(Request::create($url, $method));

        return null !== $attributes && ['PUBLIC_ACCESS'] !== $attributes;
    }

    /**
     * A URL of the route with a new UUID in every path parameter (the name of a provider in the webhook).
     */
    private static function url(string $path): string
    {
        return (string) preg_replace_callback(
            '/\{([^}]+)\}/',
            static fn (array $parameter): string => 'provider' === $parameter[1] ? 'demo-card' : Uuid::v4()->toRfc4122(),
            $path,
        );
    }

    private function existingUrl(string $method, string $path): string
    {
        $fixture = self::EXISTING_RESOURCE["$method $path"] ?? null;
        if (null === $fixture) {
            return self::url($path);
        }

        return strtr($path, $this->placeholders($fixture));
    }

    /**
     * Builds the state of the fixture.
     *
     * @return array<string, string> the value of each of its placeholders, by `{name}`
     */
    private function placeholders(string $fixture): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $placeholders = [];
        foreach (new InputContractFixtures($entityManager)->build($fixture) as $name => $value) {
            $placeholders['{'.$name.'}'] = $value;
        }

        return $placeholders;
    }

    /**
     * A new Idempotency-Key header, so an operation that needs one gets as far as its body.
     *
     * @return array<string, string>
     */
    private static function newIdempotencyKey(): array
    {
        return ['HTTP_IDEMPOTENCY_KEY' => Uuid::v4()->toRfc4122()];
    }

    /**
     * The token an operation needs: a staff member with every role in the panel, a customer under /api/v1/me.
     */
    private function tokenFor(string $path): ?string
    {
        return match (true) {
            str_starts_with($path, '/api/v1/admin/') => TestTokenFactory::staff(['admin']),
            1 === preg_match('{^/api/v1/me(/|$)}', $path) => TestTokenFactory::customer(),
            default => null,
        };
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $method, string $url, ?string $token, ?string $body = null, string $contentType = 'application/json', array $server = []): Response
    {
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $body) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        $this->client->request($method, $url, server: $server, content: $body);

        return $this->client->getResponse();
    }

    /**
     * Null when the response is the generic problem document of the status with its fixed `detail`, otherwise what
     * differs.
     */
    private function genericFailure(string $operation, Response $response, int $status, string $detail): ?string
    {
        $problem = json_decode((string) $response->getContent(), true);
        $isProblem = str_starts_with((string) $response->headers->get('Content-Type'), 'application/problem+json');
        if ($isProblem && \is_array($problem) && $status === $response->getStatusCode()
            && $detail === ($problem['detail'] ?? null) && 'about:blank' === ($problem['type'] ?? null)) {
            return null;
        }

        return \sprintf('%s: %d %s', $operation, $response->getStatusCode(), mb_substr((string) $response->getContent(), 0, 200));
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(Response $response): array
    {
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'), (string) $response->getContent());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);

        /** @var array<string, mixed> $problem */
        return $problem;
    }
}
