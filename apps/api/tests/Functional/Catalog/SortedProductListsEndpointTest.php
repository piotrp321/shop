<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

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
 * The `sort` parameter of the three lists that page the products of Catalog: GET /api/v1/admin/products, /prices and
 * /stock. Every key of the closed list in both directions with ties on the sorted column, the cursor that carries the
 * key, and the 400 for a key outside the list. On PostgreSQL (shop_test, rolled back after each test).
 */
final class SortedProductListsEndpointTest extends WebTestCase
{
    use Factories;

    private const array KEYS = ['name', '-name', '-updatedAt', 'updatedAt'];

    private KernelBrowser $client;
    /** @var list<array{id: string, name: string, updatedAt: string}> */
    private array $products = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $this->saveProducts($entityManager, $connection);
    }

    private function saveProducts(EntityManagerInterface $entityManager, Connection $connection): void
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $repository = new DoctrineProductRepository($entityManager);
        // Ties on the name (Alfa) and on the moment of the last change (the 2nd of November), so the id has to decide.
        $specs = [
            ['Delta', '2026-11-03 10:00:00'], ['Alfa', '2026-11-01 10:00:00'], ['Charlie', '2026-11-02 10:00:00'], ['Alfa', '2026-11-01 10:00:00'],
            ['Bravo', '2026-11-02 10:00:00'], ['Alfa', '2026-11-03 10:00:00'], ['Echo', '2026-11-02 10:00:00'],
        ];
        foreach ($specs as [$name, $updatedAt]) {
            $product = ProductFactory::createOne(['name' => $name, 'mainCategory' => $category]);
            $repository->save($product, null, null, new \DateTimeImmutable());
            $connection->executeStatement('UPDATE catalog_product SET updated_at = ? WHERE id = ?', [$updatedAt.'+00', $product->id()->toString()]);
            $this->products[] = ['id' => $product->id()->toString(), 'name' => $name, 'updatedAt' => $updatedAt];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listsAndKeys(): iterable
    {
        foreach (['products' => 'id', 'prices' => 'productId', 'stock' => 'productId'] as $list => $idKey) {
            foreach (self::KEYS as $key) {
                yield $list.' by '.$key => ['/api/v1/admin/'.$list, $key.'|'.$idKey];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function expectedIds(string $key): array
    {
        $column = str_ends_with($key, 'pdatedAt') ? 'updatedAt' : 'name';
        $rows = $this->products;
        usort($rows, static fn (array $a, array $b): int => [$a[$column], $a['id']] <=> [$b[$column], $b['id']]);
        if (str_starts_with($key, '-')) {
            $rows = array_reverse($rows);
        }

        return array_column($rows, 'id');
    }

    #[DataProvider('listsAndKeys')]
    public function testEveryKeySortsInBothDirectionsWithAStableKeysetAcrossPages(string $path, string $keyAndIdKey): void
    {
        [$key, $idKey] = explode('|', $keyAndIdKey);

        $seen = [];
        $query = 'sort='.rawurlencode($key).'&perPage=3';
        for ($page = 0; $page < 5; ++$page) {
            $list = $this->get($path.'?'.$query);
            self::assertResponseStatusCodeSame(200);
            self::assertIsArray($list['items']);
            $seen = [...$seen, ...array_column($list['items'], $idKey)];
            if (null === $list['next']) {
                break;
            }
            self::assertIsString($list['next']);
            $query = 'sort='.rawurlencode($key).'&perPage=3&after='.$list['next'];
        }

        self::assertSame($this->expectedIds($key), $seen);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lists(): iterable
    {
        yield 'products' => ['/api/v1/admin/products'];
        yield 'prices' => ['/api/v1/admin/prices'];
        yield 'stock' => ['/api/v1/admin/stock'];
    }

    #[DataProvider('lists')]
    public function testWithoutAKeyTheListIsSortedByName(string $path): void
    {
        $withKey = $this->get($path.'?sort=name&perPage=100');
        $without = $this->get($path.'?perPage=100');

        self::assertSame($withKey, $without);
        self::assertIsArray($without['items']);
        self::assertSame(['Alfa', 'Alfa', 'Alfa', 'Bravo', 'Charlie', 'Delta', 'Echo'], array_column($without['items'], 'name'));
    }

    #[DataProvider('lists')]
    public function testACursorOfAnotherSortIsA400AtAfter(string $path): void
    {
        foreach (self::KEYS as $issued) {
            $cursor = $this->get($path.'?perPage=1&sort='.rawurlencode($issued))['next'];
            self::assertIsString($cursor);
            foreach ([...self::KEYS, null] as $used) {
                if ($used === $issued || (null === $used && 'name' === $issued)) {
                    continue;
                }
                $this->get($path.'?after='.$cursor.(null === $used ? '' : '&sort='.rawurlencode($used)));

                self::assertSame(['after'], $this->violationPaths($issued.' used as '.($used ?? 'the default')), $issued.' used as '.($used ?? 'the default'));
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'unknown' => ['sort=price'];
        yield 'empty' => ['sort='];
        yield 'another letter case' => ['sort=Name'];
        yield 'with a space' => ['sort=-%20name'];
        yield 'a key of the orders' => ['sort=-placedAt'];
        yield 'an array' => ['sort[]=name'];
        yield 'two directions' => ['sort=--name'];
    }

    #[DataProvider('lists')]
    public function testAKeyOutsideTheClosedListIsA400AtSort(string $path): void
    {
        foreach (self::badKeys() as [$query]) {
            $this->get($path.'?'.$query);

            self::assertSame(['sort'], $this->violationPaths($path.'?'.$query), $query);
        }
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string> the property paths of the violations of the last response, which has to be a 400 (the
     *                      context says what was asked)
     */
    private function violationPaths(string $context): array
    {
        self::assertResponseStatusCodeSame(400, $context);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertIsArray($problem['violations'] ?? null);
        $paths = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $paths[] = $violation['propertyPath'];
        }

        return array_values(array_unique($paths));
    }
}
