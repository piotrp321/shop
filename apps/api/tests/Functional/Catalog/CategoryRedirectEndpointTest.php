<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Infrastructure\Demo\YamlDemoCatalogSource;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * GET /api/v1/categories/redirect?path= (spec ADMIN-FEATURES, 4 "Przekierowania") after changes made through the
 * panel, and the order of the tree after the seed (positions 0: the order by name, as before the feature).
 */
final class CategoryRedirectEndpointTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAnOldAddressLeadsToTheCurrentOneAfterEveryChange(): void
    {
        $sport = $this->create('Sport', 'sport', null);
        $run = $this->create('Bieganie', 'bieganie', $sport);
        $shoes = $this->create('Buty', 'buty', $run);
        $tourism = $this->create('Turystyka', 'turystyka', null);

        $this->admin('PUT', '/api/v1/admin/categories/'.$run, ['name' => 'Biegi', 'slug' => 'biegi', 'version' => 1]);
        self::assertSame(['path' => 'sport/biegi/buty'], $this->redirect('sport/bieganie/buty'));
        $this->assertPublicAndTagged();

        $this->admin('POST', '/api/v1/admin/categories/'.$run.'/move', ['parentId' => $tourism, 'position' => 0, 'version' => 2]);
        self::assertSame(['path' => 'turystyka/biegi/buty'], $this->redirect('sport/bieganie/buty'));
        self::assertSame(['path' => 'turystyka/biegi'], $this->redirect('sport/biegi'));
        self::assertSame(['path' => 'turystyka/biegi'], $this->redirect('sport/bieganie'));

        // The current address of a category is no redirect.
        $this->redirect('turystyka/biegi/buty');
        $this->assertNotFound();

        $this->admin('DELETE', '/api/v1/admin/categories/'.$shoes.'?version=1', null);
        self::assertResponseStatusCodeSame(204);
        $this->redirect('sport/bieganie/buty');
        $this->assertNotFound();
    }

    public function testAnAddressTakenByAnExistingCategoryIsNoLongerARedirect(): void
    {
        $sport = $this->create('Sport', 'sport', null);
        $this->admin('PUT', '/api/v1/admin/categories/'.$sport, ['name' => 'Sporty', 'slug' => 'sporty', 'version' => 1]);
        self::assertSame(['path' => 'sporty'], $this->redirect('sport'));

        $this->create('Sport', 'sport', null);

        $this->redirect('sport');
        $this->assertNotFound();
    }

    public function testASlugChangedBackRestoresTheAddress(): void
    {
        $sport = $this->create('Sport', 'sport', null);
        $this->admin('PUT', '/api/v1/admin/categories/'.$sport, ['name' => 'Sport', 'slug' => 'sporty', 'version' => 1]);
        $this->admin('PUT', '/api/v1/admin/categories/'.$sport, ['name' => 'Sport', 'slug' => 'sport', 'version' => 2]);

        self::assertSame(['path' => 'sport'], $this->redirect('sporty'));
        $this->redirect('sport');
        $this->assertNotFound();
    }

    public function testNoTokenIsNeededAndAnUnknownAddressIsACachedNotFound(): void
    {
        $this->redirect('nie-ma');

        $this->assertNotFound();
        self::assertSame('Nie ma przekierowania dla tego adresu.', $this->decode()['detail']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'missing' => [''];
        yield 'empty' => ['?path='];
        yield 'five slugs' => ['?path=a/b/c/d/e'];
        yield 'upper case' => ['?path=Sport'];
        yield 'a leading slash' => ['?path=/sport'];
        yield 'an empty segment' => ['?path=sport//buty'];
        yield 'a control character' => ['?path=sport%00'];
        yield 'an array' => ['?path[]=sport'];
    }

    #[DataProvider('invalidPaths')]
    public function testAPathOutsideTheFormatIsA400(string $query): void
    {
        $this->client->request('GET', '/api/v1/categories/redirect'.$query);

        self::assertResponseStatusCodeSame(400);
        $problem = $this->decode();
        self::assertIsArray($problem['violations']);
        self::assertIsArray($problem['violations'][0]);
        self::assertSame('path', $problem['violations'][0]['propertyPath']);
        self::assertSame('Adres kategorii to od 1 do 4 slugów rozdzielonych ukośnikiem.', $problem['violations'][0]['title']);
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertNull($headers->get('xkey'));
    }

    public function testTheResponseMatchesTheSchemaOfTheStoreSpecification(): void
    {
        $sport = $this->create('Sport', 'sport', null);
        $this->admin('PUT', '/api/v1/admin/categories/'.$sport, ['name' => 'Sport', 'slug' => 'sporty', 'version' => 1]);

        $payload = $this->redirect('sport');

        $schema = self::schemaOf('openapi.json', 'CategoryRedirectResponse');
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertSame(['path'], $schema['required']);
        self::assertEqualsCanonicalizing(array_keys((array) $schema['properties']), array_keys($payload));
        self::assertArrayHasKey('/api/v1/categories/redirect', (array) $spec['paths']);
    }

    /**
     * Criterion 3 of the task: after the seed (no positions) the store and the panel order every level of the demo
     * tree exactly by name in Polish collation, then by id, as before the order of the staff existed.
     */
    public function testTheTreeAfterTheSeedIsOrderedByNameAsBefore(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $source = self::getContainer()->get(YamlDemoCatalogSource::class);
        self::assertInstanceOf(YamlDemoCatalogSource::class, $source);
        foreach ($source->load()->categories as $category) {
            $bus->dispatch(CreateCategory::bySeed($category->id, $category->name, $category->slug, $category->parentId));
        }

        $this->client->request('GET', '/api/v1/categories');
        $public = $this->decode();
        $this->client->request('GET', '/api/v1/admin/categories', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);
        $panel = $this->decode();

        self::assertGreaterThan(3, \count($public));
        $this->assertOrderedByName($public, new \Collator('pl_PL'));
        self::assertSame($this->ids($public), $this->ids($panel));
    }

    /**
     * @param array<mixed> $nodes
     */
    private function assertOrderedByName(array $nodes, \Collator $collator): void
    {
        $sorted = $nodes;
        usort($sorted, static function (mixed $a, mixed $b) use ($collator): int {
            \assert(\is_array($a) && \is_array($b) && \is_string($a['name']) && \is_string($b['name']) && \is_string($a['id']) && \is_string($b['id']));

            return $collator->compare($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']);
        });
        self::assertSame(array_column($sorted, 'id'), array_column($nodes, 'id'));
        foreach ($nodes as $node) {
            self::assertIsArray($node);
            self::assertIsArray($node['children']);
            $this->assertOrderedByName($node['children'], $collator);
        }
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<mixed> the ids depth first
     */
    private function ids(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            self::assertIsArray($node);
            self::assertIsArray($node['children']);
            $ids = [...$ids, $node['id'], ...$this->ids($node['children'])];
        }

        return $ids;
    }

    private function create(string $name, string $slug, ?string $parentId): string
    {
        $created = $this->admin('POST', '/api/v1/admin/categories', ['name' => $name, 'slug' => $slug, 'parentId' => $parentId]);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($created['id']);

        return $created['id'];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function admin(string $method, string $path, ?array $body): array
    {
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['marketing']),
        ], content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<mixed>
     */
    private function redirect(string $path): array
    {
        $this->client->request('GET', '/api/v1/categories/redirect?path='.$path);

        return $this->decode();
    }

    private function assertPublicAndTagged(): void
    {
        self::assertResponseStatusCodeSame(200);
        $headers = $this->client->getResponse()->headers;
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
        self::assertSame('catalog', $headers->get('xkey'));
    }

    private function assertNotFound(): void
    {
        self::assertResponseStatusCodeSame(404);
        $headers = $this->client->getResponse()->headers;
        self::assertStringStartsWith('application/problem+json', (string) $headers->get('Content-Type'));
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
        self::assertSame('catalog', $headers->get('xkey'));
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
