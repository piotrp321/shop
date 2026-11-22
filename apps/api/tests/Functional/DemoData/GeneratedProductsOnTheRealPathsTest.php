<?php

declare(strict_types=1);

namespace App\Tests\Functional\DemoData;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\DemoData\Copy\Sql;
use App\Tests\Integration\DemoData\RunsDemoDataSteps;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A product of the generated tail (profile small) is read through the real paths of the store and the panel: the
 * page of the product, the listing of its category, the search and the product list of the panel. The steps run in the
 * process of the test and its transaction; `catalog:reindex` fills the index of the tests.
 */
final class GeneratedProductsOnTheRealPathsTest extends WebTestCase
{
    use RunsDemoDataSteps;

    private KernelBrowser $client;
    /** @var array{id: string, slug: string, name: string, category: string, variants: int, images: int} */
    private array $product;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The container must survive between the steps and the requests: they share one connection and its transaction.
        $this->client->disableReboot();
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')), 'shop_test has catalog rows that are not the ones of this test.');

        // DIAG (temporary): the time of every step.
        $t = hrtime(true);
        $lap = static function (string $what) use (&$t): void {
            $now = hrtime(true);
            fwrite(\STDERR, \sprintf("\n[DIAG step] %s %.3fs %s\n", new \DateTimeImmutable()->format('H:i:s.v'), ($now - $t) / 1e9, $what));
            $t = $now;
        };
        $this->succeeds('demo-data:categories');
        $lap('categories');
        $this->succeeds('demo-data:products');
        $lap('products');
        $this->succeeds('demo-data:photos');
        $lap('photos');
        $this->succeeds('catalog:reindex', [], false);
        $lap('catalog:reindex');

        // A published product with photos and several variants, from the middle of the tail.
        $row = $this->connection()->fetchAssociative(
            "SELECT p.id, p.slug, p.name, p.primary_category_id AS category, jsonb_array_length(p.images) AS images, (SELECT count(*) FROM catalog_product_variant v WHERE v.product_id = p.id) AS variants
             FROM catalog_product p WHERE p.status = 'published' AND jsonb_array_length(p.images) > 1 AND substring(p.slug from '-([0-9]+)\$')::int > 1000
               AND (SELECT count(*) FROM catalog_product_variant v WHERE v.product_id = p.id) > 2 ORDER BY p.slug LIMIT 1",
        );
        self::assertIsArray($row);
        $this->product = [
            'id' => Sql::string($row['id']),
            'slug' => Sql::string($row['slug']),
            'name' => Sql::string($row['name']),
            'category' => Sql::string($row['category']),
            'variants' => Sql::int($row['variants']),
            'images' => Sql::int($row['images']),
        ];
    }

    protected function tearDown(): void
    {
        $maintenance = self::getContainer()->get(SearchIndexMaintenance::class);
        self::assertInstanceOf(SearchIndexMaintenance::class, $maintenance);
        $t = hrtime(true);
        $maintenance->recreate();
        fwrite(\STDERR, \sprintf("\n[DIAG step] %.3fs tearDown recreate (%s)\n", (hrtime(true) - $t) / 1e9, $this->name()));
        parent::tearDown();
    }

    public function testTheProductPageOfAProductOfTheTail(): void
    {
        $body = $this->get('/api/v1/products/'.$this->product['slug']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->product['id'], $body['id']);
        self::assertSame($this->product['name'], $body['name']);
        self::assertIsArray($body['variants']);
        self::assertCount($this->product['variants'], $body['variants']);
        self::assertIsArray($body['images']);
        self::assertCount($this->product['images'], $body['images']);
        self::assertIsArray($body['breadcrumb']);
        self::assertCount(3, $body['breadcrumb'], 'department > group > leaf');
        self::assertIsArray($body['variants'][0]);
        self::assertNotSame('', $body['variants'][0]['label']);
    }

    public function testTheListingOfTheCategoryOfTheProductHoldsIt(): void
    {
        // The listing of a category holds the products that have it as their main or an additional category.
        $inCategory = $this->int($this->connection()->fetchOne(
            "SELECT count(*) FROM catalog_product p WHERE p.status = 'published' AND (p.primary_category_id = ? OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id AND pc.category_id = ?))",
            [$this->product['category'], $this->product['category']],
        ));

        [$total, $slugs] = $this->storeListing('category='.$this->product['category']);

        self::assertSame($inCategory, $total);
        self::assertCount($inCategory, $slugs);
        self::assertContains($this->product['slug'], $slugs);
    }

    public function testTheListingOfTheDepartmentHoldsPublishedProductsOnly(): void
    {
        $department = Sql::string($this->connection()->fetchOne("SELECT id FROM catalog_category WHERE parent_id IS NULL AND slug = 'bieganie'"));

        [$total, $slugs] = $this->storeListing('category='.$department);

        $published = $this->int($this->connection()->fetchOne(
            "SELECT count(*) FROM catalog_product p JOIN catalog_category c ON c.id = p.primary_category_id WHERE p.status = 'published' AND c.path LIKE ?",
            ['/'.$department.'/%'],
        ));
        self::assertSame($published, $total);
        self::assertCount($published, $slugs);
        $draftSlugs = array_map(Sql::string(...), $this->connection()->fetchFirstColumn("SELECT slug FROM catalog_product WHERE status = 'draft'"));
        self::assertSame([], array_values(array_intersect($draftSlugs, $slugs)));
    }

    public function testTheSearchFindsTheProductByItsName(): void
    {
        [, $slugs] = $this->storeListing('q='.rawurlencode($this->product['name']));

        self::assertContains($this->product['slug'], $slugs);
    }

    public function testTheProductListOfThePanelHoldsTheProductWithItsDraftsAndWithoutPhotos(): void
    {
        $body = $this->get('/api/v1/admin/products?q='.rawurlencode($this->product['name']).'', admin: true);

        self::assertResponseStatusCodeSame(200);
        self::assertContains($this->product['slug'], array_column($this->items($body), 'slug'));

        $drafts = $this->get('/api/v1/admin/products?status=draft', admin: true);
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $this->items($drafts));

        $withoutPhotos = $this->get('/api/v1/admin/products?images=none', admin: true);
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $this->items($withoutPhotos));
    }

    public function testThePanelViewOfTheProductListsEveryVariant(): void
    {
        $body = $this->get('/api/v1/admin/products/'.$this->product['id'], admin: true);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->product['name'], $body['name']);
        self::assertIsArray($body['variants']);
        self::assertCount($this->product['variants'], $body['variants']);
    }

    /**
     * Every page of the listing of the store with the query string (48 products per page).
     *
     * @return array{int, list<string>} the total the first page reports and the slugs of all pages
     */
    private function storeListing(string $query): array
    {
        $slugs = [];
        $total = 0;
        for ($page = 1; $page <= 40; ++$page) {
            $body = $this->get('/api/v1/products?'.$query.'&perPage=48&page='.$page);
            self::assertResponseStatusCodeSame(200);
            $total = Sql::int($body['total']);
            $items = $this->items($body);
            foreach ($items as $item) {
                $slugs[] = Sql::string($item['slug']);
            }
            if ([] === $items || \count($slugs) >= $total) {
                break;
            }
        }

        return [$total, $slugs];
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, bool $admin = false): array
    {
        $t = hrtime(true);
        $this->client->request('GET', $path, server: $admin ? ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])] : []);
        fwrite(\STDERR, \sprintf("\n[DIAG get] %.3fs %s\n", (hrtime(true) - $t) / 1e9, $path));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items']);

        return array_values(array_filter($body['items'], \is_array(...)));
    }
}
