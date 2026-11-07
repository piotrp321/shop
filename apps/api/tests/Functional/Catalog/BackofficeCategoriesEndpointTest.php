<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/categories on PostgreSQL (shop_test, rolled back after each test).
 */
final class BackofficeCategoriesEndpointTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturnsTheTreeSortedByPolishCollation(): void
    {
        $sport = $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Sport', 'slug' => 'sport']));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Łyżwy', 'slug' => 'lyzwy', 'parent' => $sport]));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000003', 'name' => 'Lody', 'slug' => 'lody', 'parent' => $sport]));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000004', 'name' => 'Łaźnia', 'slug' => 'laznia']));

        $this->request(TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame([
            ['id' => '00000000-0000-4000-8000-000000000004', 'name' => 'Łaźnia', 'slug' => 'laznia', 'path' => 'laznia', 'children' => []],
            [
                'id' => '00000000-0000-4000-8000-000000000001',
                'name' => 'Sport',
                'slug' => 'sport',
                'path' => 'sport',
                'children' => [
                    ['id' => '00000000-0000-4000-8000-000000000003', 'name' => 'Lody', 'slug' => 'lody', 'path' => 'sport/lody', 'children' => []],
                    ['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Łyżwy', 'slug' => 'lyzwy', 'path' => 'sport/lyzwy', 'children' => []],
                ],
            ],
        ], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testEmptyCatalogueReturnsAnEmptyArray(): void
    {
        $this->request(TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('[]', (string) $this->client->getResponse()->getContent());
    }

    public function testResponseIsPrivateAndNotStored(): void
    {
        $this->request(TestTokenFactory::staff(['admin']));

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'marketing' => [['marketing']];
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testEveryStaffRoleCanReadTheTree(array $roles): void
    {
        $this->request(TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->request(null);

        $this->assertUnauthorized();
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $this->request(TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    public function testResponseMatchesTheCategoryNodeSchemaOfTheAdminSpecification(): void
    {
        $this->save(CategoryFactory::createOne());

        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/v1/admin/categories', $spec['paths']);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas']['CategoryNode'] ?? null;
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);

        $this->request(TestTokenFactory::staff(['admin']));
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload[0]);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($payload[0]));
    }

    private function request(?string $token): void
    {
        $this->client->request('GET', '/api/v1/admin/categories', server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function assertUnauthorized(): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    private function save(Category $category): Category
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        new DoctrineCategoryRepository($entityManager)->save($category);

        return $category;
    }
}
