<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What the functional tests of the product endpoints of the panel share: a client, tokens of the staff roles, JSON
 * requests, a category, an original in the image storage and the checks of problem documents and of the OpenAPI schemas.
 * On shop_test, rolled back after each test (the originals in the test bucket of Garage stay: their keys follow their
 * content).
 */
trait ProductAdminEndpointTestTrait
{
    private const string PROBLEM_PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private Category $category;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->category = CategoryFactory::createOne(['name' => 'Obuwie']);
        new DoctrineCategoryRepository($this->entityManager)->save($this->category);
    }

    /**
     * @param list<string>|null $roles null: no token at all
     */
    private static function token(?array $roles): ?string
    {
        return null === $roles ? null : TestTokenFactory::staff($roles);
    }

    /**
     * @param array<mixed>|null     $body
     * @param array<string, string> $server
     *
     * @return array<mixed> the decoded JSON of the response
     */
    private function api(string $method, string $path, ?array $body = null, ?string $token = 'admin', array $server = []): array
    {
        $headers = $server;
        if (null !== $body) {
            $headers['CONTENT_TYPE'] = 'application/json';
        }
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.('admin' === $token ? TestTokenFactory::staff(['admin']) : $token);
        }
        $this->client->request($method, $path, server: $headers, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->decoded();
    }

    /**
     * @return array<mixed>
     */
    private function decoded(): array
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
     * An original stored in the test bucket of Garage under the key an upload would give it, written straight to the
     * bucket so the image storage service of the application stays untouched (a test may replace it).
     */
    private function originalKey(string $fixture = 'sample.jpg'): string
    {
        $contents = (string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/images/'.$fixture);
        $key = 'originals/'.hash('sha256', $contents).'.'.pathinfo($fixture, \PATHINFO_EXTENSION);
        $bucket = self::getContainer()->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $bucket);
        $bucket->write($key, $contents);

        return $key;
    }

    /**
     * A body that creates a valid product.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function body(array $changes = []): array
    {
        return [
            'name' => 'Buty biegowe',
            'slug' => 'buty-biegowe',
            'shortDescription' => 'Lekkie buty na szlak.',
            'mainCategoryId' => $this->category->id()->toString(),
            'additionalCategoryIds' => [],
            'taxClass' => 'standard',
            'images' => [['key' => $this->originalKey(), 'alt' => 'Buty z boku']],
            'variantAxes' => ['Rozmiar'],
            'variants' => [['variantId' => null, 'values' => ['M']], ['variantId' => null, 'values' => ['L']]],
            ...$changes,
        ];
    }

    /**
     * The body of an edit from the view of a product: its data and version, with the given changes.
     *
     * @param array<mixed>         $view
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function editBody(array $view, array $changes = []): array
    {
        return [
            'version' => $view['version'],
            'name' => $view['name'],
            'slug' => $view['slug'],
            'shortDescription' => $view['shortDescription'],
            'mainCategoryId' => $view['mainCategoryId'],
            'additionalCategoryIds' => $view['additionalCategoryIds'],
            'taxClass' => $view['taxClass'],
            'images' => array_map(static fn (array $image): array => ['key' => $image['key'], 'alt' => $image['alt']], self::list($view['images'] ?? [])),
            'variantAxes' => $view['variantAxes'],
            'variants' => $view['variants'],
            ...$changes,
        ];
    }

    /**
     * Creates a product through the API as an administrator and returns its view.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private function createProduct(array $changes = []): array
    {
        $view = $this->api('POST', '/api/v1/admin/products', $this->body($changes));
        self::assertResponseStatusCodeSame(201);

        return $view;
    }

    /**
     * @param array<mixed> $view
     *
     * @return array<mixed>
     */
    private function publish(array $view): array
    {
        $published = $this->api('POST', '/api/v1/admin/products/'.self::string($view['id']).'/publish', ['version' => $view['version']]);
        self::assertResponseStatusCodeSame(200);

        return $published;
    }

    /**
     * @return list<array{propertyPath: string, title: string}>
     */
    private function violations(int $status = 422): array
    {
        $problem = $this->assertProblem($status);
        self::assertIsArray($problem['violations'] ?? null);
        $violations = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            self::assertIsString($violation['title']);
            $violations[] = ['propertyPath' => $violation['propertyPath'], 'title' => $violation['title']];
        }

        return $violations;
    }

    /**
     * @return array<mixed>
     */
    private function assertProblem(int $status, ?string $type = null, ?string $detail = null): array
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $problem = $this->decoded();
        self::assertSame($status, $problem['status']);
        self::assertSame(null === $type ? 'about:blank' : self::PROBLEM_PREFIX.$type, $problem['type']);
        if (null !== $detail) {
            self::assertSame($detail, $problem['detail'] ?? null);
        }

        return $problem;
    }

    private function assertUnauthorized(): void
    {
        $this->assertProblem(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    private function assertPrivateAndNotStored(): void
    {
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    /**
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName] ?? null;
        self::assertIsArray($schema, $schemaName.' must be in openapi-admin.json.');
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);

        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($object), $schemaName.' keys must match the schema.');
        self::assertEqualsCanonicalizing($schema['required'], array_keys($object), $schemaName.' requires every key.');
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        /** @var list<array<mixed>> $value */
        return $value;
    }
}
