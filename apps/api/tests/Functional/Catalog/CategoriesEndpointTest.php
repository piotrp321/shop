<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Tests\Factory\CategoryFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class CategoriesEndpointTest extends WebTestCase
{
    use Factories;

    public function testEmptyCatalogueReturnsEmptyArray(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/categories');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('[]', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testReturnsNestedTreeSortedByPolishCollation(): void
    {
        $client = static::createClient();
        $sport = $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Sport']));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Łyżwy', 'parent' => $sport]));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000003', 'name' => 'Lody', 'parent' => $sport]));
        $aaa = $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000004', 'name' => 'Aaa', 'parent' => $sport]));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000005', 'name' => 'Deep', 'parent' => $aaa]));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000006', 'name' => 'Łaźnia']));
        $this->save(CategoryFactory::createOne(['id' => '00000000-0000-4000-8000-000000000007', 'name' => 'Lampy']));

        $client->request('GET', '/api/v1/categories');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['id' => '00000000-0000-4000-8000-000000000007', 'name' => 'Lampy', 'children' => []],
            ['id' => '00000000-0000-4000-8000-000000000006', 'name' => 'Łaźnia', 'children' => []],
            [
                'id' => '00000000-0000-4000-8000-000000000001',
                'name' => 'Sport',
                'children' => [
                    [
                        'id' => '00000000-0000-4000-8000-000000000004',
                        'name' => 'Aaa',
                        'children' => [
                            ['id' => '00000000-0000-4000-8000-000000000005', 'name' => 'Deep', 'children' => []],
                        ],
                    ],
                    ['id' => '00000000-0000-4000-8000-000000000003', 'name' => 'Lody', 'children' => []],
                    ['id' => '00000000-0000-4000-8000-000000000002', 'name' => 'Łyżwy', 'children' => []],
                ],
            ],
        ], $this->decode($client->getResponse()->getContent()));
    }

    public function testPolishTailoringDiffersFromRootCollation(): void
    {
        $client = static::createClient();
        foreach (['Śruby', 'Szafy', 'Sanki', 'Ćwiczenia', 'Czapki', 'Cegły'] as $name) {
            $this->save(CategoryFactory::createOne(['name' => $name]));
        }

        $client->request('GET', '/api/v1/categories');

        // Root/en_US collation would give Cegły, Ćwiczenia, Czapki, Sanki, Śruby, Szafy.
        self::assertSame(
            ['Cegły', 'Czapki', 'Ćwiczenia', 'Sanki', 'Szafy', 'Śruby'],
            array_column($this->decode($client->getResponse()->getContent()), 'name'),
        );
    }

    public function testResponseIsPubliclyCachedAndVariesOnOrigin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/categories');

        $headers = $client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertStringContainsString('Origin', (string) $headers->get('Vary'));
    }

    public function testWrongMethodReturns405Problem(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/categories');

        self::assertResponseStatusCodeSame(405);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testResponseMatchesTheCategoryNodeSchemaOfTheExportedSpecification(): void
    {
        $client = static::createClient();
        $root = $this->save(CategoryFactory::createOne());
        $child = $this->save(CategoryFactory::createOne(['parent' => $root]));
        $this->save(CategoryFactory::createOne(['parent' => $child]));

        $client->request('GET', '/api/v1/categories');

        $spec = $this->decode(file_get_contents(\dirname(__DIR__, 3).'/openapi.json'));
        $schema = self::arrayAt($spec, 'paths', '/api/v1/categories', 'get', 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', self::at($schema, 'type'));
        self::assertSame('#/components/schemas/CategoryNode', self::at($schema, 'items', '$ref'));

        $nodeSchema = self::arrayAt($spec, 'components', 'schemas', 'CategoryNode');
        $payload = $this->decode($client->getResponse()->getContent());
        self::assertNotSame([], $payload);
        foreach ($payload as $node) {
            $this->assertMatchesNodeSchema($node, $nodeSchema);
        }
    }

    /**
     * @param array<mixed> $nodeSchema
     */
    private function assertMatchesNodeSchema(mixed $node, array $nodeSchema): void
    {
        self::assertIsArray($node);
        self::assertSame('object', self::at($nodeSchema, 'type'));
        $properties = self::arrayAt($nodeSchema, 'properties');
        self::assertEqualsCanonicalizing(array_keys($properties), array_keys($node), 'Node keys must match the schema properties.');
        self::assertEqualsCanonicalizing(self::arrayAt($nodeSchema, 'required'), array_keys($node));
        self::assertSame('string', self::at($properties, 'id', 'type'));
        self::assertIsString($node['id']);
        self::assertSame('string', self::at($properties, 'name', 'type'));
        self::assertIsString($node['name']);
        self::assertSame('array', self::at($properties, 'children', 'type'));
        self::assertSame('#/components/schemas/CategoryNode', self::at($properties, 'children', 'items', '$ref'));
        self::assertIsArray($node['children']);
        self::assertTrue(array_is_list($node['children']));
        foreach ($node['children'] as $child) {
            $this->assertMatchesNodeSchema($child, $nodeSchema);
        }
    }

    /**
     * Walks nested arrays by keys, asserting that every step exists.
     *
     * @param array<mixed> $data
     */
    private static function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function arrayAt(array $data, string ...$keys): array
    {
        $value = self::at($data, ...$keys);
        self::assertIsArray($value);

        return $value;
    }

    private function save(Category $category): Category
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new DoctrineCategoryRepository($entityManager))->save($category);

        return $category;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string|false $json): array
    {
        $decoded = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
