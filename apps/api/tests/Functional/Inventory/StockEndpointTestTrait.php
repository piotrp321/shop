<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What the tests of the stock endpoints of the panel share: a client, Catalog variants, the stored stock and its
 * history, and the checks of problem documents and of the OpenAPI schema. On shop_test, rolled back after each test.
 */
trait StockEndpointTestTrait
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * The default variant of a new Catalog product; its id is never the id of the product.
     */
    private function variant(): string
    {
        return $this->productWithVariant()[1];
    }

    /**
     * @return array{string, string} the id of a new Catalog product and the id of its default variant
     */
    private function productWithVariant(): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($this->entityManager)->save($product);
        $variantId = $product->variants()->toList()[0]->id()->toString();
        self::assertNotSame($product->id()->toString(), $variantId);

        return [$product->id()->toString(), $variantId];
    }

    /**
     * @param array<string, string> $headers HTTP headers by their $_SERVER name
     *
     * @return array<mixed>
     */
    private function post(string $path, string $body, ?string $token, array $headers = [], string $contentType = 'application/json'): array
    {
        $server = ['CONTENT_TYPE' => $contentType, ...$headers];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request('POST', $path, server: $server, content: $body);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array{physical: int, reserved: int, version: int}|null
     */
    private function storedStock(string $variantId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT physical, reserved, version FROM inventory_stock_item WHERE variant_id = ?', [$variantId]);
        if (false === $row) {
            return null;
        }
        \assert(\is_int($row['physical']) && \is_int($row['reserved']) && \is_int($row['version']));

        return ['physical' => $row['physical'], 'reserved' => $row['reserved'], 'version' => $row['version']];
    }

    /**
     * The history of the variant without the receipt of the stock fixture.
     *
     * @return list<array<string, mixed>>
     */
    private function movements(string $variantId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT type, physical_delta, physical_after, reason, note, reference, source, actor_id, actor_name FROM inventory_stock_movement WHERE variant_id = ? AND (reference IS NULL OR reference NOT LIKE 'fixture:%') ORDER BY id",
            [$variantId],
        );
    }

    /**
     * @return array<mixed>
     */
    private function assertProblem(int $status): array
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($status, $problem['status']);
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());

        return $problem;
    }

    /**
     * @return list<string>
     */
    private function violationPaths(int $status = 422): array
    {
        $problem = $this->assertProblem($status);
        self::assertIsArray($problem['violations'] ?? null);
        $paths = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $paths[$violation['propertyPath']] = $violation['propertyPath'];
        }

        return array_values($paths);
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

        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($object), $schemaName.' keys must match the schema.');
    }

    /**
     * @param array<mixed> $stock
     */
    private static function assertUtcTimestamp(array $stock): void
    {
        self::assertIsString($stock['updatedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $stock['updatedAt']);
    }
}
