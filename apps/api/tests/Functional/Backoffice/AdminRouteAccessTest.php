<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Every route of the panel API, taken from the router at runtime, needs a staff member with a staff role
 * (spec: docs/features/admin-features/spec.md, section 0 and "Model zagrożeń"). Only GET /api/v1/admin/me answers
 * a backoffice token without a staff role, so the panel can tell the member they have no role. A new panel route
 * without ROLE_STAFF fails this test without any change to it.
 */
final class AdminRouteAccessTest extends WebTestCase
{
    /** The firewall pattern of the panel (config/packages/security.yaml). */
    private const string ADMIN_PATH = '{^/api/v1/admin(/|$)}';

    /** Methods tried on a route that does not restrict them. */
    private const array ANY_METHOD = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const string ME = '/api/v1/admin/me';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheRouterListsThePanelRoutes(): void
    {
        $endpoints = array_map(static fn (array $endpoint): string => $endpoint[0].' '.$endpoint[2], $this->adminEndpoints());

        self::assertGreaterThanOrEqual(25, \count($endpoints));
        foreach ([
            'GET /api/v1/admin/me',
            'GET /api/v1/admin/dashboard',
            'GET /api/v1/admin/categories',
            'POST /api/v1/admin/products',
            'PUT /api/v1/admin/products/{id}',
            'DELETE /api/v1/admin/promotions/{id}',
            'GET /api/v1/admin/variants/{variantId}/stock-history',
            'GET /api/v1/admin/delivery-methods',
            'POST /api/v1/admin/delivery-methods',
            'GET /api/v1/admin/delivery-methods/{id}',
            'PUT /api/v1/admin/delivery-methods/{id}',
        ] as $known) {
            self::assertContains($known, $endpoints);
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolelessTokens(): iterable
    {
        yield 'no realm roles' => [[]];
        yield 'only roles that are not staff roles' => [['offline_access', 'uma_authorization', 'default-roles-backoffice']];
        yield 'staff role names in another case' => [['Admin', 'SUPPORT', 'ROLE_STAFF']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolelessTokens')]
    public function testABackofficeTokenWithoutAStaffRoleIsForbiddenEverywhereButMe(array $roles): void
    {
        $failures = [];
        foreach ($this->adminEndpoints() as [$method, $url, $path]) {
            $response = $this->request($method, $url, TestTokenFactory::staff($roles));
            $expected = 'GET' === $method && self::ME === $path ? 200 : 403;
            if ($expected !== $response->getStatusCode()) {
                $failures[] = \sprintf('%s %s: %d instead of %d', $method, $url, $response->getStatusCode(), $expected);
            } elseif (403 === $expected) {
                $this->assertForbidden($response);
            }
        }

        self::assertSame([], $failures);
    }

    public function testMeAnswersAMemberWithoutAStaffRoleWithNoRoles(): void
    {
        $response = $this->request('GET', self::ME, TestTokenFactory::staff(['offline_access']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['id' => TestTokenFactory::STAFF_ID, 'username' => 'admin', 'roles' => []],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testHeadOfMeIsAllowedLikeGet(): void
    {
        self::assertSame(200, $this->request('HEAD', self::ME, TestTokenFactory::staff([]))->getStatusCode());
    }

    /**
     * Requests that only look like GET /api/v1/admin/me never get past the staff role rule.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function nearMe(): iterable
    {
        yield 'trailing slash' => ['GET', '/api/v1/admin/me/'];
        yield 'longer segment' => ['GET', '/api/v1/admin/meX'];
        yield 'sub-path' => ['GET', '/api/v1/admin/me/x'];
        yield 'encoded trailing new line' => ['GET', '/api/v1/admin/me%0A'];
        yield 'encoded trailing slash' => ['GET', '/api/v1/admin/me%2F'];
        yield 'prefix' => ['GET', '/api/v1/admin/m'];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            yield "$method of me" => [$method, self::ME];
        }
    }

    #[DataProvider('nearMe')]
    public function testOnlyGetAndHeadOfMeLetAMemberWithoutAStaffRoleIn(string $method, string $url): void
    {
        $response = $this->request($method, $url, TestTokenFactory::staff([]));

        self::assertContains($response->getStatusCode(), [403, 404, 405]);
        self::assertStringNotContainsString(TestTokenFactory::STAFF_ID, (string) $response->getContent());
    }

    public function testACustomerTokenIsUnauthorizedEverywhere(): void
    {
        $this->assertUnauthorizedEverywhere(static fn (): string => TestTokenFactory::customer());
    }

    public function testNoTokenIsUnauthorizedEverywhere(): void
    {
        $this->assertUnauthorizedEverywhere(static fn (): ?string => null);
    }

    /**
     * Every staff role still reaches GET /api/v1/admin/dashboard, which has no rule of its own.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function staffRoles(): iterable
    {
        foreach (['admin', 'support', 'warehouse', 'marketing'] as $role) {
            yield $role => [[$role]];
        }
        yield 'a staff role among other realm roles' => [['offline_access', 'warehouse']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testAMemberWithAStaffRolePassesTheStaffRule(array $roles): void
    {
        self::assertSame(200, $this->request('GET', '/api/v1/admin/categories', TestTokenFactory::staff($roles))->getStatusCode());
    }

    /**
     * @param \Closure(): ?string $token
     */
    private function assertUnauthorizedEverywhere(\Closure $token): void
    {
        $failures = [];
        foreach ($this->adminEndpoints() as [$method, $url]) {
            $response = $this->request($method, $url, $token());
            if (401 !== $response->getStatusCode()) {
                $failures[] = \sprintf('%s %s: %d instead of 401', $method, $url, $response->getStatusCode());
            } elseif ('Bearer' !== $response->headers->get('WWW-Authenticate')) {
                $failures[] = \sprintf('%s %s: no Bearer challenge', $method, $url);
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * Each method of each panel route: the method, the URL with every path parameter set to a random UUID, and the
     * route path.
     *
     * @return list<array{string, string, string}>
     */
    private function adminEndpoints(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $endpoints = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            $path = $route->getPath();
            if (1 !== preg_match(self::ADMIN_PATH, $path)) {
                continue;
            }
            $url = (string) preg_replace_callback('/\{[^}]+\}/', static fn (): string => Uuid::v4()->toRfc4122(), $path);
            foreach ($route->getMethods() ?: self::ANY_METHOD as $method) {
                $endpoints[] = [$method, $url, $path];
            }
        }

        return $endpoints;
    }

    private function request(string $method, string $url, ?string $token): Response
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request($method, $url, server: $server);

        return $this->client->getResponse();
    }

    private function assertForbidden(Response $response): void
    {
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'Access is denied.'],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
