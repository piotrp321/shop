<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The category management of the panel (spec ADMIN-FEATURES, 4; P9, P10) on PostgreSQL (shop_test, rolled back after
 * each test): POST /admin/categories, PUT /admin/categories/{id}, POST /admin/categories/{id}/move,
 * DELETE /admin/categories/{id}?version= and GET /admin/categories?withCounts=true.
 */
final class CategoryManagementEndpointTest extends WebTestCase
{
    use Factories;

    private const string SPORT = '00000000-0000-4000-8000-000000000001';
    private const string RUN = '00000000-0000-4000-8000-000000000002';
    private const string SHOES = '00000000-0000-4000-8000-000000000003';
    private const string TOURISM = '00000000-0000-4000-8000-000000000004';
    private const string UNKNOWN = '00000000-0000-4000-8000-0000000000ff';
    private const string PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolesWithoutTheRight(): iterable
    {
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesWithoutTheRight')]
    public function testOtherStaffMayNotChangeTheTreeAndAreAskedBeforeTheBody(array $roles): void
    {
        $this->sportTree();
        $token = TestTokenFactory::staff($roles);

        $this->request('POST', '/api/v1/admin/categories', '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('PUT', '/api/v1/admin/categories/'.self::RUN, '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('POST', '/api/v1/admin/categories/'.self::RUN.'/move', '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('DELETE', '/api/v1/admin/categories/'.self::SHOES.'?version=x', null, $token);
        $this->assertProblem(403);
        self::assertSame(4, $this->connection->fetchOne('SELECT count(*) FROM catalog_category'));
        self::assertSame(1, $this->connection->fetchOne('SELECT max(version) FROM catalog_category'));
    }

    public function testWithoutATokenEveryWriteIsUnauthorized(): void
    {
        $this->request('POST', '/api/v1/admin/categories', '{}', null);
        $this->assertProblem(401);
        $this->request('DELETE', '/api/v1/admin/categories/'.self::RUN.'?version=1', null, TestTokenFactory::customer());
        $this->assertProblem(401);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function managers(): iterable
    {
        yield 'admin' => ['admin'];
        yield 'marketing' => ['marketing'];
    }

    #[DataProvider('managers')]
    public function testACreatedCategoryIsLastAmongItsSiblings(string $role): void
    {
        $this->sportTree();

        $created = $this->send('POST', '/api/v1/admin/categories', ['name' => 'Akcesoria', 'slug' => 'akcesoria', 'parentId' => null], $role);

        self::assertResponseStatusCodeSame(201);
        $this->assertNotStored();
        self::assertIsString($created['id']);
        self::assertSame(['id' => $created['id'], 'name' => 'Akcesoria', 'slug' => 'akcesoria', 'path' => 'akcesoria', 'parentId' => null, 'version' => 1], $created);
        $this->assertKeysMatchSchema($created, 'BackofficeCategoryResponse');
        self::assertSame(['sport', 'turystyka', 'akcesoria'], array_column($this->tree(), 'slug'));
    }

    public function testAChildIsCreatedUnderItsParent(): void
    {
        $this->sportTree();

        $created = $this->send('POST', '/api/v1/admin/categories', ['name' => '  Kolce  ', 'slug' => 'kolce', 'parentId' => self::RUN]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Kolce', $created['name']);
        self::assertSame('sport/bieganie/kolce', $created['path']);
        self::assertSame(self::RUN, $created['parentId']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'a missing key' => [['name' => 'A', 'slug' => 'a'], 'parentId', 'To pole jest wymagane.'];
        yield 'a name of another type' => [['name' => 5, 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi być tekstem.'];
        yield 'an empty name' => [['name' => '', 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        yield 'a name of NBSP' => [['name' => "\u{00A0}\u{2003}", 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        yield 'a name with a NUL' => [['name' => "Zima\0", 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        yield 'a name with a line separator' => [['name' => "Zima\u{2028}", 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        yield 'a name of 101 characters' => [['name' => str_repeat('ż', 101), 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        // Spec api-input-contract, T3 and #632 F4: a name without a visible character is no name.
        foreach ([0x200B, 0xFEFF, 0xAD, 0x2060, 0x200E, 0x202E] as $codePoint) {
            yield \sprintf('a name of U+%04X', $codePoint) => [['name' => mb_chr($codePoint), 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        }
        yield 'a name with a C1 control character' => [['name' => "Zi\u{9F}ma", 'slug' => 'a', 'parentId' => null], 'name', 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.'];
        yield 'an upper case slug' => [['name' => 'A', 'slug' => 'Sport', 'parentId' => null], 'slug', 'Slug może mieć 1–60 znaków: małe litery a–z i cyfry, słowa oddzielone pojedynczym myślnikiem.'];
        yield 'a slug with polish letters' => [['name' => 'A', 'slug' => 'łyżwy', 'parentId' => null], 'slug', 'Slug może mieć 1–60 znaków: małe litery a–z i cyfry, słowa oddzielone pojedynczym myślnikiem.'];
        yield 'a slug of another type' => [['name' => 'A', 'slug' => ['a'], 'parentId' => null], 'slug', 'Slug musi być tekstem.'];
        yield 'a parent that is no UUID' => [['name' => 'A', 'slug' => 'a', 'parentId' => 'sport'], 'parentId', 'Kategoria nadrzędna nie istnieje.'];
        yield 'an unknown parent' => [['name' => 'A', 'slug' => 'a', 'parentId' => self::UNKNOWN], 'parentId', 'Kategoria nadrzędna nie istnieje.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidCreations')]
    public function testAnInvalidCreationIsA422AtItsField(array $body, string $field, string $message): void
    {
        $this->sportTree();

        $problem = $this->send('POST', '/api/v1/admin/categories', $body);

        $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertContains(['propertyPath' => $field, 'title' => $message], array_map(
            static fn (mixed $violation): array => \is_array($violation) ? ['propertyPath' => $violation['propertyPath'] ?? null, 'title' => $violation['title'] ?? null] : [],
            $problem['violations'],
        ));
        self::assertSame(4, $this->connection->fetchOne('SELECT count(*) FROM catalog_category'));
    }

    public function testASlugOfASiblingAndAFifthLevelAreConflicts(): void
    {
        $this->sportTree();
        $this->send('POST', '/api/v1/admin/categories', ['name' => 'Bieg', 'slug' => 'bieganie', 'parentId' => self::SPORT]);
        self::assertSame(self::PREFIX.'category-slug-taken', $this->assertProblem(409)['type']);

        $leaf = $this->send('POST', '/api/v1/admin/categories', ['name' => 'Trail', 'slug' => 'trail', 'parentId' => self::SHOES]);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($leaf['id']);
        $this->send('POST', '/api/v1/admin/categories', ['name' => 'Za głęboko', 'slug' => 'za-gleboko', 'parentId' => $leaf['id']]);
        $problem = $this->assertProblem(409);
        self::assertSame(self::PREFIX.'category-too-deep', $problem['type']);
        self::assertSame('Drzewo kategorii może mieć najwyżej 4 poziomy.', $problem['detail']);
    }

    public function testARevisionChangesTheNameAndSlugAndRaisesTheVersion(): void
    {
        $this->sportTree();

        $revised = $this->send('PUT', '/api/v1/admin/categories/'.self::RUN, ['name' => 'Biegi', 'slug' => 'biegi', 'version' => 1]);

        self::assertResponseStatusCodeSame(200);
        $this->assertNotStored();
        self::assertSame(['id' => self::RUN, 'name' => 'Biegi', 'slug' => 'biegi', 'path' => 'sport/biegi', 'parentId' => self::SPORT, 'version' => 2], $revised);
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM catalog_category_redirect'));
    }

    public function testAStaleVersionIsAConflictAndChangesNothing(): void
    {
        $this->sportTree();

        $this->send('PUT', '/api/v1/admin/categories/'.self::RUN, ['name' => 'Biegi', 'slug' => 'biegi', 'version' => 2]);
        $problem = $this->assertProblem(409);
        self::assertSame(self::PREFIX.'category-modified', $problem['type']);
        self::assertSame('Kategoria została w międzyczasie zmieniona. Odśwież drzewo i spróbuj ponownie.', $problem['detail']);

        $this->send('POST', '/api/v1/admin/categories/'.self::RUN.'/move', ['parentId' => null, 'position' => 0, 'version' => 5]);
        self::assertSame(self::PREFIX.'category-modified', $this->assertProblem(409)['type']);
        $this->request('DELETE', '/api/v1/admin/categories/'.self::SHOES.'?version=2', null, TestTokenFactory::staff(['admin']));
        self::assertSame(self::PREFIX.'category-modified', $this->assertProblem(409)['type']);
        self::assertSame('bieganie', $this->connection->fetchOne('SELECT slug FROM catalog_category WHERE id = ?', [self::RUN]));
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        foreach ([self::UNKNOWN, 'not-a-uuid'] as $id) {
            $this->send('PUT', '/api/v1/admin/categories/'.$id, ['name' => 'X', 'slug' => 'x', 'version' => 1]);
            self::assertSame('Kategoria nie istnieje.', $this->assertProblem(404)['detail']);
            $this->send('POST', '/api/v1/admin/categories/'.$id.'/move', ['parentId' => null, 'position' => 0, 'version' => 1]);
            $this->assertProblem(404);
            $this->request('DELETE', '/api/v1/admin/categories/'.$id.'?version=1', null, TestTokenFactory::staff(['admin']));
            $this->assertProblem(404);
        }
    }

    public function testAMoveChangesTheParentAndTheFirstReorderMovesOnlyTheChosenCategory(): void
    {
        $this->sportTree();
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000011', 'name' => 'Akcesoria', 'slug' => 'akcesoria']));

        $moved = $this->send('POST', '/api/v1/admin/categories/'.self::SHOES.'/move', ['parentId' => self::TOURISM, 'position' => 0, 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->assertNotStored();
        self::assertSame('turystyka/buty', $moved['path']);
        self::assertSame(2, $moved['version']);

        $this->send('POST', '/api/v1/admin/categories/'.self::TOURISM.'/move', ['parentId' => null, 'position' => 0, 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['turystyka', 'akcesoria', 'sport'], array_column($this->tree(), 'slug'));
        self::assertSame(['turystyka', 'akcesoria', 'sport'], array_column($this->publicTree(), 'slug'));
    }

    public function testAMoveIntoTheOwnSubtreeOrTooDeepIsAConflict(): void
    {
        $this->sportTree();

        $this->send('POST', '/api/v1/admin/categories/'.self::SPORT.'/move', ['parentId' => self::SHOES, 'position' => 0, 'version' => 1]);
        $problem = $this->assertProblem(409);
        self::assertSame(self::PREFIX.'category-cycle', $problem['type']);
        self::assertSame('Nie można przenieść kategorii do niej samej ani do jej podkategorii.', $problem['detail']);

        $this->send('POST', '/api/v1/admin/categories/'.self::SPORT.'/move', ['parentId' => self::TOURISM, 'position' => 0, 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->send('POST', '/api/v1/admin/categories/'.self::TOURISM.'/move', ['parentId' => null, 'position' => 0, 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->send('POST', '/api/v1/admin/categories', ['name' => 'Inna', 'slug' => 'inna', 'parentId' => null]);
        $other = $this->connection->fetchOne("SELECT id FROM catalog_category WHERE slug = 'inna'");
        self::assertIsString($other);
        // turystyka / sport / bieganie / buty has four levels; under another root it would have five.
        $this->send('POST', '/api/v1/admin/categories/'.self::TOURISM.'/move', ['parentId' => $other, 'position' => 0, 'version' => 2]);
        self::assertSame(self::PREFIX.'category-too-deep', $this->assertProblem(409)['type']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidMoves(): iterable
    {
        yield 'a negative position' => [['parentId' => null, 'position' => -1, 'version' => 1], 'position', 'Pozycja musi być liczbą całkowitą od 0.'];
        yield 'a position as text' => [['parentId' => null, 'position' => '1', 'version' => 1], 'position', 'Pozycja musi być liczbą całkowitą od 0.'];
        yield 'a version as text' => [['parentId' => null, 'position' => 0, 'version' => '1'], 'version', 'Wersja musi być dodatnią liczbą całkowitą.'];
        yield 'a missing parent' => [['position' => 0, 'version' => 1], 'parentId', 'To pole jest wymagane.'];
        yield 'an unknown parent' => [['parentId' => self::UNKNOWN, 'position' => 0, 'version' => 1], 'parentId', 'Kategoria nadrzędna nie istnieje.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidMoves')]
    public function testAnInvalidMoveIsA422AtItsField(array $body, string $field, string $message): void
    {
        $this->sportTree();

        $problem = $this->send('POST', '/api/v1/admin/categories/'.self::RUN.'/move', $body);

        $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertIsArray($problem['violations'][0]);
        self::assertSame($field, $problem['violations'][0]['propertyPath']);
        self::assertSame($message, $problem['violations'][0]['title']);
    }

    public function testOnlyAnEmptyCategoryIsDeleted(): void
    {
        [, $run, $shoes] = $this->sportTree();
        $this->product($shoes);
        $this->product($run, [$shoes], ProductStatus::Draft);

        $this->request('DELETE', '/api/v1/admin/categories/'.self::SHOES.'?version=1', null, TestTokenFactory::staff(['marketing']));
        $problem = $this->assertProblem(409);
        self::assertSame(self::PREFIX.'category-not-empty', $problem['type']);
        self::assertSame('Przenieś produkty do innej kategorii, zanim usuniesz tę.', $problem['detail']);
        self::assertSame(['value' => 0, 'capped' => false], $problem['subcategoryCount']);
        self::assertSame(['value' => 2, 'capped' => false], $problem['productCount']);

        $this->request('DELETE', '/api/v1/admin/categories/'.self::RUN.'?version=1', null, TestTokenFactory::staff(['marketing']));
        self::assertSame(['value' => 1, 'capped' => false], $this->assertProblem(409)['subcategoryCount']);

        $this->request('DELETE', '/api/v1/admin/categories/'.self::TOURISM.'?version=1', null, TestTokenFactory::staff(['marketing']));
        self::assertResponseStatusCodeSame(204);
        $this->assertNotStored();
        self::assertSame(3, $this->connection->fetchOne('SELECT count(*) FROM catalog_category'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'missing' => [''];
        yield 'zero' => ['?version=0'];
        yield 'text' => ['?version=abc'];
        yield 'an array' => ['?version[]=1'];
    }

    #[DataProvider('invalidVersions')]
    public function testADeletionWithoutAValidVersionIsA400(string $query): void
    {
        $this->sportTree();

        $this->request('DELETE', '/api/v1/admin/categories/'.self::TOURISM.$query, null, TestTokenFactory::staff(['admin']));

        $problem = $this->assertProblem(400);
        self::assertIsArray($problem['violations']);
        self::assertSame(['propertyPath' => 'version', 'title' => 'Wersja musi być dodatnią liczbą całkowitą.'], array_intersect_key((array) $problem['violations'][0], ['propertyPath' => 1, 'title' => 1]));
    }

    public function testTheTreeOfThePanelHasVersionsAndCountsOnlyWhenAsked(): void
    {
        [, $run, $shoes] = $this->sportTree();
        $this->product($shoes);
        $this->product($run, [$shoes], ProductStatus::Draft);
        $this->send('PUT', '/api/v1/admin/categories/'.self::TOURISM, ['name' => 'Góry', 'slug' => 'gory', 'version' => 1]);

        $plain = $this->tree();
        self::assertSame(['gory', 'sport'], array_column($plain, 'slug'));
        self::assertNull($plain[0]['productCount']);
        self::assertSame(2, $plain[0]['version']);
        self::assertSame(1, $plain[1]['version']);

        $this->request('GET', '/api/v1/admin/categories?withCounts=true', null, TestTokenFactory::staff(['warehouse']));
        self::assertResponseStatusCodeSame(200);
        $counted = $this->decode();
        self::assertIsArray($counted[0]);
        self::assertSame(['value' => 0, 'capped' => false], $counted[0]['productCount']);
        self::assertIsArray($counted[1]);
        self::assertSame(['value' => 0, 'capped' => false], $counted[1]['productCount']);
        self::assertIsArray($counted[1]['children']);
        self::assertIsArray($counted[1]['children'][0]);
        self::assertSame(['value' => 1, 'capped' => false], $counted[1]['children'][0]['productCount']);
        self::assertIsArray($counted[1]['children'][0]['children']);
        self::assertIsArray($counted[1]['children'][0]['children'][0]);
        self::assertSame(['value' => 2, 'capped' => false], $counted[1]['children'][0]['children'][0]['productCount']);
    }

    public function testCountsAreAskedOnlyWithTrue(): void
    {
        $this->request('GET', '/api/v1/admin/categories?withCounts=yes', null, TestTokenFactory::staff(['admin']));

        $problem = $this->assertProblem(400);
        self::assertIsArray($problem['violations']);
        self::assertSame(['propertyPath' => 'withCounts', 'title' => 'Parametr withCounts przyjmuje tylko wartość true.'], array_intersect_key((array) $problem['violations'][0], ['propertyPath' => 1, 'title' => 1]));
    }

    public function testEveryConflictTypeIsInTheSchemaOfTheAdminSpecification(): void
    {
        $properties = self::schemaOf('openapi-admin.json', 'CategoryConflictProblem')['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['type']);
        $enum = $properties['type']['enum'] ?? null;

        self::assertSame([
            self::PREFIX.'category-modified',
            self::PREFIX.'category-slug-taken',
            self::PREFIX.'category-too-deep',
            self::PREFIX.'category-cycle',
            self::PREFIX.'category-not-empty',
        ], $enum);
    }

    /**
     * @return array{Category, Category, Category, Category} sport, sport/bieganie, sport/bieganie/buty, turystyka
     */
    private function sportTree(): array
    {
        $sport = $this->save(CategoryFactory::createOne(['id' => self::SPORT, 'name' => 'Sport', 'slug' => 'sport']));
        $run = $this->save(CategoryFactory::createOne(['id' => self::RUN, 'name' => 'Bieganie', 'slug' => 'bieganie', 'parent' => $sport]));
        $shoes = $this->save(CategoryFactory::createOne(['id' => self::SHOES, 'name' => 'Buty', 'slug' => 'buty', 'parent' => $run]));
        $tourism = $this->save(CategoryFactory::createOne(['id' => self::TOURISM, 'name' => 'Turystyka', 'slug' => 'turystyka']));

        return [$sport, $run, $shoes, $tourism];
    }

    private function save(Category $category): Category
    {
        new DoctrineCategoryRepository($this->entityManager())->save($category);

        return $category;
    }

    /**
     * @param list<Category> $additional
     */
    private function product(Category $main, array $additional = [], ProductStatus $status = ProductStatus::Published): void
    {
        new DoctrineProductRepository($this->entityManager())->save(
            ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => $additional, 'status' => $status]),
            null,
            null,
            new \DateTimeImmutable(),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tree(): array
    {
        $this->request('GET', '/api/v1/admin/categories', null, TestTokenFactory::staff(['support']));
        self::assertResponseStatusCodeSame(200);
        /** @var list<array<string, mixed>> $tree */
        $tree = $this->decode();

        return $tree;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicTree(): array
    {
        $this->client->request('GET', '/api/v1/categories');
        self::assertResponseStatusCodeSame(200);
        /** @var list<array<string, mixed>> $tree */
        $tree = $this->decode();

        return $tree;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $body, string $role = 'admin'): array
    {
        return $this->request($method, $path, json_encode($body, \JSON_THROW_ON_ERROR), TestTokenFactory::staff([$role]));
    }

    /**
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?string $body, ?string $token): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $path, server: $server, content: $body ?? '');

        return $this->decode();
    }

    /**
     * @return array<mixed>
     */
    private function decode(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private function assertProblem(int $status): array
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $problem = $this->decode();
        self::assertSame($status, $problem['status']);
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());
        $this->assertNotStored();

        return $problem;
    }

    private function assertNotStored(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('private'), (string) $headers->get('Cache-Control'));
        self::assertTrue($headers->hasCacheControlDirective('no-store'), (string) $headers->get('Cache-Control'));
    }

    /**
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        $properties = self::schemaOf('openapi-admin.json', $schemaName)['properties'] ?? null;
        self::assertIsArray($properties, $schemaName);
        self::assertEqualsCanonicalizing(array_keys($properties), array_keys($object), $schemaName.' keys must match the schema.');
    }

    /**
     * @return array<mixed>
     */
    private static function schemaOf(string $document, string $name): array
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/'.$document), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        $components = $spec['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $schema = $schemas[$name] ?? null;
        self::assertIsArray($schema, $name);

        return $schema;
    }
}
