<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Port\ProductTableStatistics;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresProductTableStatistics;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\InMemory\FixedCountProductIndexer;
use App\Tests\Support\MessengerRows;
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
        $body = $this->json($client->getResponse());
        self::assertSame(['productCount' => ['value' => 3, 'estimated' => true], 'categoryCount' => 2], $body['catalog']);
        self::assertSame(['catalog', 'queues', 'sales', 'system'], array_keys($body));
        self::assertNull($body['sales']);
        self::assertNull($body['system']);
    }

    public function testAdminGetsTheSystemSectionWithAllServicesInTheFixedOrder(): void
    {
        $client = static::createClient();
        $this->seedCatalog();
        $this->useIndexedDocuments(2);

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json($client->getResponse());
        self::assertSame(['productCount' => ['value' => 3, 'estimated' => true], 'categoryCount' => 2], $body['catalog']);
        $system = $body['system'];
        self::assertIsArray($system);
        self::assertSame(['services', 'outbox', 'queues', 'searchIndex', 'messages'], array_keys($system));

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
        self::assertSame(['indexedProducts' => 2, 'catalogProducts' => 3, 'inSync' => false], $system['searchIndex']);
    }

    public function testAdminGetsTheMessagesOfTheQueuesInTheDatabase(): void
    {
        $client = static::createClient();
        $connection = $this->connection();
        MessengerRows::clear($connection);
        MessengerRows::add($connection, 'failed', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('2026-11-19 08:30:00 UTC'));
        MessengerRows::add($connection, 'failed', new \DateTimeImmutable('-1 minute'), new \DateTimeImmutable('2026-11-20 08:30:00 UTC'));
        MessengerRows::add($connection, 'outbox', new \DateTimeImmutable('-90 seconds'), times: 2);
        MessengerRows::add($connection, 'scheduled', new \DateTimeImmutable('-3 seconds'), new \DateTimeImmutable('-35 minutes'));
        MessengerRows::add($connection, 'scheduled', new \DateTimeImmutable('+20 minutes'));
        MessengerRows::add($connection, 'jobs', new \DateTimeImmutable('-20 minutes'));

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $messages = $this->messages($this->json($client->getResponse()));
        self::assertSame(['healthy', 'failed', 'transports'], array_keys($messages));
        self::assertFalse($messages['healthy']);
        self::assertSame(['count' => 2, 'capped' => false, 'oldestAt' => '2026-11-19T08:30:00+00:00'], $messages['failed']);
        self::assertIsArray($messages['transports']);
        self::assertSame(['outbox', 'scheduled', 'payment_simulator', 'promotions', 'jobs'], array_column($messages['transports'], 'name'));
        $byName = array_column($messages['transports'], null, 'name');
        self::assertSame(['name', 'ready', 'capped', 'oldestReadyAgeSeconds', 'stalled'], array_keys($byName['outbox']));
        self::assertSame(['ready' => 2, 'capped' => false, 'stalled' => false], array_intersect_key($byName['outbox'], array_flip(['ready', 'capped', 'stalled'])));
        self::assertEqualsWithDelta(90, $byName['outbox']['oldestReadyAgeSeconds'], 5);
        self::assertSame(['ready' => 1, 'capped' => false, 'stalled' => false], array_intersect_key($byName['scheduled'], array_flip(['ready', 'capped', 'stalled'])), 'the delayed message that is not due is not ready; the due one has just become');
        self::assertEqualsWithDelta(3, $byName['scheduled']['oldestReadyAgeSeconds'], 5);
        self::assertSame(['ready' => 0, 'capped' => false, 'oldestReadyAgeSeconds' => null, 'stalled' => false], array_diff_key($byName['promotions'], ['name' => true]));
        self::assertSame(['ready' => 1, 'capped' => false, 'stalled' => true], array_intersect_key($byName['jobs'], array_flip(['ready', 'capped', 'stalled'])));
        self::assertEqualsWithDelta(1200, $byName['jobs']['oldestReadyAgeSeconds'], 5);
    }

    public function testEmptyQueuesAreHealthy(): void
    {
        $client = static::createClient();
        MessengerRows::clear($this->connection());

        $this->get($client, TestTokenFactory::staff(['admin']));

        $messages = $this->messages($this->json($client->getResponse()));
        self::assertTrue($messages['healthy']);
        self::assertSame(['count' => 0, 'capped' => false, 'oldestAt' => null], $messages['failed']);
        self::assertIsArray($messages['transports']);
        foreach ($messages['transports'] as $transport) {
            self::assertIsArray($transport);
            self::assertSame(0, $transport['ready']);
            self::assertNull($transport['oldestReadyAgeSeconds']);
            self::assertFalse($transport['stalled']);
        }
    }

    public function testCountsAreLimitedTo1000WithTheCappedMark(): void
    {
        $client = static::createClient();
        $connection = $this->connection();
        MessengerRows::clear($connection);
        MessengerRows::add($connection, 'failed', times: 1001);
        MessengerRows::add($connection, 'jobs', new \DateTimeImmutable('-5 seconds'), times: 1000);
        MessengerRows::add($connection, 'outbox', new \DateTimeImmutable('-5 seconds'), times: 1001);

        $this->get($client, TestTokenFactory::staff(['admin']));

        $messages = $this->messages($this->json($client->getResponse()));
        self::assertIsArray($messages['failed']);
        self::assertSame([1000, true], [$messages['failed']['count'], $messages['failed']['capped']]);
        self::assertIsArray($messages['transports']);
        $byName = array_column($messages['transports'], null, 'name');
        self::assertSame([1000, true], [$byName['outbox']['ready'], $byName['outbox']['capped']]);
        self::assertSame([1000, false], [$byName['jobs']['ready'], $byName['jobs']['capped']]);
    }

    public function testAQueueThatStandsOnlyAboveTheLimitOf600Seconds(): void
    {
        $client = static::createClient();
        $connection = $this->connection();
        MessengerRows::clear($connection);
        MessengerRows::add($connection, 'promotions', new \DateTimeImmutable('-9 minutes'));
        MessengerRows::add($connection, 'payment_simulator', new \DateTimeImmutable('-11 minutes'));

        $this->get($client, TestTokenFactory::staff(['admin']));

        $messages = $this->messages($this->json($client->getResponse()));
        self::assertIsArray($messages['transports']);
        $byName = array_column($messages['transports'], null, 'name');
        self::assertFalse($byName['promotions']['stalled']);
        self::assertTrue($byName['payment_simulator']['stalled']);
        self::assertFalse($messages['healthy']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function messages(array $body): array
    {
        self::assertIsArray($body['system']);
        self::assertIsArray($body['system']['messages']);

        /** @var array<string, mixed> $messages */
        $messages = $body['system']['messages'];

        return $messages;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        return $connection;
    }

    public function testSearchIndexWithAsManyDocumentsAsProductsIsInSync(): void
    {
        $client = static::createClient();
        $this->seedCatalog();
        $this->useIndexedDocuments(3);

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $system = $this->json($client->getResponse())['system'];
        self::assertIsArray($system);
        self::assertSame(['indexedProducts' => 3, 'catalogProducts' => 3, 'inSync' => true], $system['searchIndex']);
    }

    public function testRealSearchIndexIsCounted(): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $system = $this->json($client->getResponse())['system'];
        self::assertIsArray($system);
        self::assertIsArray($system['searchIndex']);
        self::assertIsInt($system['searchIndex']['indexedProducts']);
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

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolesWithoutAStaffRole(): iterable
    {
        yield 'no staff role' => [['offline_access']];
        yield 'no roles' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesWithoutAStaffRole')]
    public function testAMemberWithoutAStaffRoleIsForbidden(array $roles): void
    {
        $client = static::createClient();

        $this->get($client, TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(403);
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

    /**
     * Through the real provider and both query buses: the failure of the repository is wrapped by the handler's bus,
     * by the provider's bus, and must still come out as a 503.
     */
    #[DataProvider('staffRoles')]
    public function testLostDatabaseConnectionIsAServiceUnavailableProblem(string $role): void
    {
        $client = static::createClient();
        self::getContainer()->set(PostgresProductTableStatistics::class, new class implements ProductTableStatistics {
            public function estimatedProductCount(): ?int
            {
                $driverFailure = new class('SQLSTATE[08006] connection to server at "postgres" failed') extends \Exception implements DriverException {
                    public function getSQLState(): string
                    {
                        return '08006';
                    }
                };

                throw new ConnectionException($driverFailure, null);
            }

            public function refresh(): void
            {
                throw new \LogicException('Not used.');
            }
        });

        $this->get($client, TestTokenFactory::staff([$role]));

        $response = $client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503], $this->json($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => ['admin'];
        yield 'support' => ['support'];
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
        self::assertSame(['productCount' => ['value' => 3, 'estimated' => true], 'categoryCount' => 2], $body['catalog']);
        self::assertIsArray($body['system']);
        self::assertSame(['indexedProducts' => null, 'catalogProducts' => 3, 'inSync' => false], $body['system']['searchIndex']);
    }

    public function testWithoutAnEstimateOfTheProductsTheCountIsNullAndTheIndexIsNeitherInSyncNorOutOfIt(): void
    {
        $client = static::createClient();
        $this->seedCatalog();
        $this->useIndexedDocuments(3);
        self::getContainer()->set(PostgresProductTableStatistics::class, new class implements ProductTableStatistics {
            public function estimatedProductCount(): ?int
            {
                return null;
            }

            public function refresh(): void
            {
                throw new \LogicException('Not used.');
            }
        });

        $this->get($client, TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json($client->getResponse());
        self::assertSame(['productCount' => null, 'categoryCount' => 2], $body['catalog']);
        self::assertIsArray($body['system']);
        self::assertSame(['indexedProducts' => 3, 'catalogProducts' => null, 'inSync' => null], $body['system']['searchIndex']);
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

    private function useIndexedDocuments(int $count): void
    {
        self::getContainer()->set(ElasticsearchProductIndexer::class, new FixedCountProductIndexer([$count]));
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

        $products->save(ProductFactory::createOne(['mainCategory' => $shoes, 'imageAlts' => ['Front']]), null, null, new \DateTimeImmutable());
        $products->save(ProductFactory::createOne(['mainCategory' => $shoes]), null, null, new \DateTimeImmutable());
        $products->save(ProductFactory::createOne(['mainCategory' => $sport]), null, null, new \DateTimeImmutable());
        // The number of products on the dashboard is the estimate of the statistics, which a mass change refreshes.
        $entityManager->getConnection()->executeStatement('ANALYZE catalog_product');
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
