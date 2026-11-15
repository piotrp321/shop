<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The queues of the dashboard per role (the voters of the modules that own them decide), with counts that stop at
 * 1000, the estimated number of products, and the schema of the exported specification. On shop_test, rolled back
 * after each test.
 */
final class DashboardQueuesEndpointTest extends WebTestCase
{
    use Factories;

    private const array QUEUES = ['paidToShip', 'awaitingPayment', 'pendingRefunds', 'overdueRefunds', 'outOfStockVariants', 'lowStockVariants', 'productsWithoutImages'];

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        BackofficeOrderFixture::noOrders($this->connection);
        $this->connection->executeStatement('DELETE FROM inventory_stock_movement');
        $this->connection->executeStatement('DELETE FROM inventory_stock_item');
    }

    /**
     * @return iterable<string, array{list<string>, array<string, ?array{value: int, capped: bool}>}> the roles and the queues they see; the rest is null
     */
    public static function roles(): iterable
    {
        $count = static fn (int $value): array => ['value' => $value, 'capped' => false];

        yield 'admin' => [['admin'], [
            'paidToShip' => $count(3), 'awaitingPayment' => $count(2), 'pendingRefunds' => $count(2), 'overdueRefunds' => $count(1),
            'outOfStockVariants' => $count(3), 'lowStockVariants' => $count(2), 'productsWithoutImages' => $count(2),
        ]];
        yield 'support' => [['support'], [
            'paidToShip' => $count(3), 'awaitingPayment' => $count(2), 'pendingRefunds' => $count(2), 'overdueRefunds' => $count(1),
        ]];
        yield 'warehouse' => [['warehouse'], [
            'paidToShip' => $count(3), 'outOfStockVariants' => $count(3), 'lowStockVariants' => $count(2),
        ]];
        yield 'marketing' => [['marketing'], ['productsWithoutImages' => $count(2)]];
        yield 'support and marketing' => [['support', 'marketing'], [
            'paidToShip' => $count(3), 'awaitingPayment' => $count(2), 'pendingRefunds' => $count(2), 'overdueRefunds' => $count(1), 'productsWithoutImages' => $count(2),
        ]];
    }

    /**
     * @param list<string>                                    $roles
     * @param array<string, ?array{value: int, capped: bool}> $visible
     */
    #[DataProvider('roles')]
    public function testEveryRoleSeesExactlyItsQueuesAndNullForTheOthers(array $roles, array $visible): void
    {
        $this->seed();

        $body = $this->dashboard(TestTokenFactory::staff($roles));

        $expected = array_fill_keys(self::QUEUES, null);
        foreach ($visible as $queue => $count) {
            $expected[$queue] = $count;
        }
        self::assertSame($expected, $body['queues']);
    }

    public function testACountAboveTheLimitOfTheDashboardIsCappedAt1000(): void
    {
        $this->seed();
        // 1 001 more orders awaiting payment and 1 001 more variants with a low stock.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ordering_order (
                    id, number, status, owner_type, customer_id, access_token, email, full_name, phone, street, postal_code, city, country, currency,
                    total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline, version
                )
                SELECT gen_random_uuid(), 'R-9'||n, 'new', 'guest', NULL, substr(md5('t'||n)||md5('u'||n), 1, 43), 'a'||n||'@example.com', 'Jan Kowalski',
                       '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 0, 1000, 'many-'||n, repeat('a', 64), now(), now() + interval '30 minutes', 1
                FROM generate_series(1, 1001) n
                SQL,
        );
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at) SELECT gen_random_uuid(), gen_random_uuid(), 3, 0, 1, now() FROM generate_series(1, 1001)',
        );

        $queues = $this->dashboard(TestTokenFactory::staff(['admin']))['queues'];

        self::assertIsArray($queues);
        self::assertSame(['value' => 1000, 'capped' => true], $queues['awaitingPayment']);
        self::assertSame(['value' => 1000, 'capped' => true], $queues['lowStockVariants']);
        self::assertSame(['value' => 3, 'capped' => false], $queues['paidToShip']);
        self::assertSame(['value' => 3, 'capped' => false], $queues['outOfStockVariants']);
    }

    public function testExactlyTheLimitIsNotCapped(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at) SELECT gen_random_uuid(), gen_random_uuid(), 3, 0, 1, now() FROM generate_series(1, 1000)',
        );

        $queues = $this->dashboard(TestTokenFactory::staff(['warehouse']))['queues'];

        self::assertIsArray($queues);
        self::assertSame(['value' => 1000, 'capped' => false], $queues['lowStockVariants']);
    }

    public function testTheProductCountIsTheEstimateOfTheDatabaseMarkedAsEstimated(): void
    {
        $this->seed();

        $catalog = $this->dashboard(TestTokenFactory::staff(['support']))['catalog'];

        self::assertSame(['productCount' => ['value' => 3, 'estimated' => true], 'categoryCount' => 2], $catalog);
    }

    public function testResponseMatchesTheQueuesSchemaOfTheExportedSpecification(): void
    {
        $body = $this->dashboard(TestTokenFactory::staff(['admin']));

        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        $schemas = $spec['components']['schemas'];
        self::assertIsArray($schemas);
        self::assertIsArray($schemas['DashboardQueuesResponse']);
        self::assertIsArray($schemas['DashboardQueuesResponse']['properties']);
        self::assertIsArray($body['queues']);
        self::assertEqualsCanonicalizing(array_keys($schemas['DashboardQueuesResponse']['properties']), array_keys($body['queues']));
        self::assertEqualsCanonicalizing(self::QUEUES, array_keys($body['queues']));
        self::assertIsArray($schemas['DashboardCatalogResponse']);
        self::assertIsArray($schemas['DashboardCatalogResponse']['properties']);
        self::assertIsArray($body['catalog']);
        self::assertEqualsCanonicalizing(array_keys($schemas['DashboardCatalogResponse']['properties']), array_keys($body['catalog']));
    }

    /**
     * Paid 3, new 2; refunds: a pending one overdue, a pending one in the future and a completed one; stock: 3 variants
     * out of stock (one by its reservation), 2 low (one by its reservation), one in stock, one out of stock without its
     * product (belongs to no queue); products: 3, two of them without images.
     */
    private function seed(): void
    {
        foreach (['R-100001', 'R-100002', 'R-100003'] as $number) {
            BackofficeOrderFixture::order($this->connection, 'paid', '-2 days', $number);
        }
        foreach (['R-100004', 'R-100005'] as $number) {
            BackofficeOrderFixture::order($this->connection, 'new', '-1 hour', $number);
        }
        BackofficeOrderFixture::order($this->connection, 'returned', '-20 days', 'R-100006', refundDueAt: self::at('-2 days'));
        BackofficeOrderFixture::order($this->connection, 'returned', '-3 days', 'R-100007', refundDueAt: self::at('+5 days'));
        BackofficeOrderFixture::order($this->connection, 'returned', '-20 days', 'R-100008', refundDueAt: self::at('-6 days'), refundCompletedAt: self::at('-5 days'));
        BackofficeOrderFixture::order($this->connection, 'delivered', '-10 days', 'R-100009');

        foreach ([[0, 0, true], [4, 4, true], [9, 9, true], [3, 0, true], [8, 5, true], [20, 0, true], [0, 0, false]] as [$physical, $reserved, $withProduct]) {
            $this->connection->executeStatement(
                'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at) VALUES (?, ?, ?, ?, 1, now())',
                [(string) Uuid::v7(), $withProduct ? (string) Uuid::v7() : null, $physical, $reserved],
            );
        }

        $categories = new DoctrineCategoryRepository($this->entityManager);
        $products = new DoctrineProductRepository($this->entityManager);
        $sport = CategoryFactory::createOne(['name' => 'Sport']);
        $categories->save($sport);
        $shoes = CategoryFactory::createOne(['name' => 'Buty', 'parent' => $sport]);
        $categories->save($shoes);
        $products->save(ProductFactory::createOne(['mainCategory' => $shoes, 'imageAlts' => ['Front']]), null, null, new \DateTimeImmutable());
        $products->save(ProductFactory::createOne(['mainCategory' => $shoes]), null, null, new \DateTimeImmutable());
        $products->save(ProductFactory::createOne(['mainCategory' => $sport]), null, null, new \DateTimeImmutable());
        $this->connection->executeStatement('ANALYZE catalog_product');
    }

    private static function at(string $relative): string
    {
        return new \DateTimeImmutable($relative)->format('Y-m-d H:i:sP');
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(string $token): array
    {
        $this->client->request('GET', '/api/v1/admin/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
