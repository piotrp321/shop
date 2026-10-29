<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\CatalogStatisticsProvider;
use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * The dashboard on the real database and probes (the stack must be up).
 */
final class DashboardEndpointTest extends WebTestCase
{
    use Factories;

    private const array SERVICES = ['postgres', 'redis', 'elasticsearch', 'rabbitmq', 'keycloak', 'garage', 'imgproxy', 'mailpit'];

    public function testSupportGetsTheCatalogSectionAndNoSystemSection(): void
    {
        $client = static::createClient();
        $this->seedCatalog();

        $this->get($client, TestTokenFactory::staff(['support']));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['catalog' => ['productCount' => 3, 'categoryCount' => 2, 'productsWithoutImages' => 2], 'system' => null],
            $this->json($client->getResponse()),
        );
    }

    public function testAdminGetsTheSystemSectionWithAllServicesInTheFixedOrder(): void
    {
        $client = static::createClient();
        $this->seedCatalog();

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json($client->getResponse());
        self::assertSame(['productCount' => 3, 'categoryCount' => 2, 'productsWithoutImages' => 2], $body['catalog']);
        $system = $body['system'];
        self::assertIsArray($system);
        self::assertSame(['services', 'outbox', 'queues', 'searchIndex'], array_keys($system));

        self::assertIsArray($system['services']);
        self::assertSame(self::SERVICES, array_column($system['services'], 'name'));
        foreach ($system['services'] as $service) {
            self::assertIsArray($service);
            self::assertSame(['name', 'status', 'latencyMs'], array_keys($service));
            self::assertSame('up', $service['status'], \sprintf('%s should be up', \is_string($service['name']) ? $service['name'] : '?'));
            self::assertIsInt($service['latencyMs']);
        }

        self::assertIsArray($system['outbox']);
        self::assertIsInt($system['outbox']['pending']);
        self::assertIsArray($system['queues']);
        self::assertIsArray($system['searchIndex']);
        self::assertSame(3, $system['searchIndex']['catalogProducts']);
        self::assertIsInt($system['searchIndex']['indexedProducts']);
        // Products created through the repository are not indexed here, so the index lags behind.
        self::assertFalse($system['searchIndex']['inSync']);
    }

    public function testAdminWithOtherRolesStillGetsTheSystemSection(): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::staff(['support', 'admin', 'offline_access']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($this->json($client->getResponse())['system']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolesWithoutSystemAccess(): iterable
    {
        yield 'warehouse' => [['warehouse']];
        yield 'marketing' => [['marketing']];
        yield 'no staff role' => [['offline_access']];
        yield 'no roles' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesWithoutSystemAccess')]
    public function testOtherStaffRolesGetNoSystemSection(array $roles): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json($client->getResponse())['system']);
    }

    public function testResponseIsNeverCached(): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCustomerTokenIsUnauthorized(): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::customer());

        $this->assertUnauthorized($client->getResponse());
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/dashboard');

        $this->assertUnauthorized($client->getResponse());
    }

    public function testExpiredTokenIsUnauthorized(): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::staff(['admin'], ['iat' => time() - 600, 'exp' => time() - 1]));

        $this->assertUnauthorized($client->getResponse());
    }

    public function testDatabaseConnectionFailureIsAServiceUnavailableProblem(): void
    {
        $client = static::createClient();
        self::getContainer()->set(CatalogStatisticsProvider::class, new class implements CatalogStatisticsProvider {
            public function statistics(): CatalogStatistics
            {
                $driverFailure = new class('SQLSTATE[08006] connection to server at "postgres" failed') extends \Exception implements DriverException {
                    public function getSQLState(): string
                    {
                        return '08006';
                    }
                };

                throw new ConnectionException($driverFailure, null);
            }
        });

        $this->get($client, TestTokenFactory::staff(['admin']));

        $response = $client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503], $this->json($response));
        self::assertStringNotContainsString('postgres', (string) $response->getContent());
    }

    public function testUnreachableSearchIndexGivesNullIndexedProductsAndNoSync(): void
    {
        $client = static::createClient();
        $this->seedCatalog();
        self::getContainer()->set(ElasticsearchProductIndexer::class, new class implements ProductIndexer {
            public function index(ProductDocument $document): IndexOutcome
            {
                throw new SearchUnavailable('down');
            }

            public function countDocuments(): int
            {
                throw new SearchUnavailable('Elasticsearch is unreachable.');
            }
        });

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json($client->getResponse());
        self::assertSame(['productCount' => 3, 'categoryCount' => 2, 'productsWithoutImages' => 2], $body['catalog']);
        self::assertIsArray($body['system']);
        self::assertSame(['indexedProducts' => null, 'catalogProducts' => 3, 'inSync' => false], $body['system']['searchIndex']);
    }

    public function testResponseMatchesTheDashboardSchemaOfTheExportedSpecification(): void
    {
        $client = static::createClient();
        $this->get($client, TestTokenFactory::staff(['admin']));

        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/v1/admin/dashboard', $spec['paths']);
        self::assertIsArray($spec['components']);
        $schemas = $spec['components']['schemas'];
        self::assertIsArray($schemas);
        $body = $this->json($client->getResponse());

        $dashboard = $schemas['DashboardResponse'];
        self::assertIsArray($dashboard);
        self::assertIsArray($dashboard['properties']);
        self::assertEqualsCanonicalizing(array_keys($dashboard['properties']), array_keys($body));
        $system = $schemas['DashboardSystemResponse'];
        self::assertIsArray($system);
        self::assertIsArray($system['properties']);
        self::assertIsArray($body['system']);
        self::assertEqualsCanonicalizing(array_keys($system['properties']), array_keys($body['system']));
    }

    private function seedCatalog(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $categories = new DoctrineCategoryRepository($entityManager);
        $products = new DoctrineProductRepository($entityManager);

        $sport = CategoryFactory::createOne(['name' => 'Sport']);
        $categories->save($sport);
        $shoes = CategoryFactory::createOne(['name' => 'Buty', 'parent' => $sport]);
        $categories->save($shoes);

        $products->save(ProductFactory::createOne(['mainCategory' => $shoes, 'imageAlts' => ['Front']]));
        $products->save(ProductFactory::createOne(['mainCategory' => $shoes]));
        $products->save(ProductFactory::createOne(['mainCategory' => $sport]));
    }

    private function get(KernelBrowser $client, string $token): void
    {
        $client->request('GET', '/api/v1/admin/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function assertUnauthorized(Response $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
