<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the tests of the cart endpoints share: a client, products in Catalog with a price in Pricing and a stock in
 * Inventory, requests, and the checks of problem documents, of `Cache-Control` and of the OpenAPI schema.
 * On shop_test, rolled back after each test.
 */
trait CartEndpointTestTrait
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * A product of Catalog, with a gross price of its one variant (null: none) and a physical stock of that variant
     * (null: no stock record).
     *
     * @param list<string> $imageAlts
     */
    private function product(?int $gross = 10_000, ?int $stock = 50, string $name = 'Buty Puls', array $imageAlts = []): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => $name, 'imageAlts' => $imageAlts]);
        new DoctrineProductRepository($this->entityManager)->save($product);
        $id = $product->id()->toString();
        if (null !== $gross) {
            VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $product->variants()->toList()[0]->id()->toString(), 'gross' => $gross]);
        }
        if (null !== $stock) {
            StockFixture::stock($this->connection, $stock, variantId: $product->variants()->toList()[0]->id()->toString());
        }
        $this->entityManager->clear();

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?string $body = null, ?string $token = null, string $contentType = 'application/json'): array
    {
        $server = null === $body ? [] : ['CONTENT_TYPE' => $contentType];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $path, server: $server, content: $body);
        $this->entityManager->clear();

        $content = (string) $this->client->getResponse()->getContent();
        $decoded = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertProblem(int $status, ?string $detail, array $problem): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($status, $problem['status'] ?? null);
        if (null !== $detail) {
            self::assertSame($detail, $problem['detail'] ?? null);
        }
        $this->assertNoStore();
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertViolation(string $propertyPath, array $problem): void
    {
        $this->assertProblem(422, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertContains($propertyPath, array_column($problem['violations'], 'propertyPath'));
    }

    /**
     * A 422 whose `violations` are exactly the given pairs of property path and title, in order: each field gives
     * one violation, in Polish.
     *
     * @param list<array{string, string}> $expected
     * @param array<mixed>                $problem
     */
    private function assertViolations(array $expected, array $problem): void
    {
        $this->assertProblem(422, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        $actual = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            $actual[] = [$violation['propertyPath'] ?? null, $violation['title'] ?? null];
        }
        self::assertSame($expected, $actual);
    }

    private function assertNoStore(): void
    {
        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        $directives = array_map('trim', explode(',', $cacheControl));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
    }

    /**
     * The keys of a response against a schema of the exported specification, recursively for nested schemas.
     *
     * @param array<mixed> $body
     */
    private static function assertMatchesSchema(string $schemaName, array $body): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        self::assertSchema($spec['components']['schemas'], $schemaName, $body);
    }

    /**
     * @param array<mixed> $schemas
     * @param array<mixed> $body
     */
    private static function assertSchema(array $schemas, string $name, array $body): void
    {
        $schema = $schemas[$name] ?? null;
        self::assertIsArray($schema, $name);
        self::assertIsArray($schema['properties']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($body), $name);
        foreach ($schema['properties'] as $property => $definition) {
            \assert(\is_array($definition));
            $ref = self::reference($definition) ?? self::reference(\is_array($definition['allOf'] ?? null) && \is_array($definition['allOf'][0] ?? null) ? $definition['allOf'][0] : []) ?? self::reference(\is_array($definition['items'] ?? null) ? $definition['items'] : []);
            $value = $body[$property];
            if (null === $ref || !\is_array($value) || [] === $value) {
                continue;
            }
            $nested = substr($ref, \strlen('#/components/schemas/'));
            foreach (isset($definition['items']) ? $value : [$value] as $item) {
                if (\is_array($item) && 'ProductImage' !== $nested) {
                    self::assertSchema($schemas, $nested, $item);
                }
            }
        }
    }

    /**
     * @param array<mixed> $definition
     */
    private static function reference(array $definition): ?string
    {
        return \is_string($definition['$ref'] ?? null) ? $definition['$ref'] : null;
    }

    private function response(): Response
    {
        return $this->client->getResponse();
    }
}
