<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pricing;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\PriceHistoryRecordFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/variants/{variantId}/price-history on PostgreSQL (shop_test, rolled back after each test).
 */
final class PriceHistoryEndpointTest extends WebTestCase
{
    use Factories;

    private const string UNKNOWN_VARIANT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testListsTheChangesNewestFirstWithTheirSnapshot(): void
    {
        $variantId = $this->variant();
        $this->entry($variantId, '2026-10-01 10:00:00+00:00', 5490, ['source' => 'seed', 'actorId' => null, 'actorName' => null, 'taxClass' => 'printed_book', 'netAmount' => 5229, 'vatAmount' => 261, 'vatRateBp' => 500]);
        $this->entry($variantId, '2026-10-03 12:30:00+02:00', 5990, ['source' => 'tax_reconciliation', 'actorId' => null, 'actorName' => null, 'taxClass' => 'printed_book', 'netAmount' => 5705, 'vatAmount' => 285, 'vatRateBp' => 500]);
        $this->entry($variantId, '2026-10-02 10:00:00+00:00', 5790, ['source' => 'staff', 'actorId' => 'sub-123', 'actorName' => 'Anna Admin', 'taxClass' => 'printed_book', 'netAmount' => 5514, 'vatAmount' => 276, 'vatRateBp' => 500]);
        $this->entry($this->variant(), '2026-10-04 10:00:00+00:00', 1000);

        $body = $this->get($variantId);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNull($body['next']);
        $items = $this->items($body);
        self::assertSame(
            [
                ['changedAt' => '2026-10-03T10:30:00+00:00', 'currency' => 'PLN', 'gross' => 5990, 'net' => 5705, 'vat' => 285, 'vatRate' => 500, 'taxClass' => 'printed_book', 'source' => 'tax_reconciliation', 'actorName' => null],
                ['changedAt' => '2026-10-02T10:00:00+00:00', 'currency' => 'PLN', 'gross' => 5790, 'net' => 5514, 'vat' => 276, 'vatRate' => 500, 'taxClass' => 'printed_book', 'source' => 'staff', 'actorName' => 'Anna Admin'],
                ['changedAt' => '2026-10-01T10:00:00+00:00', 'currency' => 'PLN', 'gross' => 5490, 'net' => 5229, 'vat' => 261, 'vatRate' => 500, 'taxClass' => 'printed_book', 'source' => 'seed', 'actorName' => null],
            ],
            $items,
        );
        $this->assertKeysMatchSchema($body, 'PriceHistoryResponse');
        $this->assertKeysMatchSchema($items[0], 'PriceHistoryItemResponse');
        self::assertStringNotContainsString('sub-123', (string) $this->client->getResponse()->getContent());
    }

    public function testAVariantWithoutAPriceHasAnEmptyHistory(): void
    {
        self::assertSame(['items' => [], 'next' => null], $this->get($this->variant()));
        self::assertResponseStatusCodeSame(200);
    }

    public function testDefaultPageHoldsTwentyFiveEntriesAndTheCursorLeadsToTheRest(): void
    {
        $variantId = $this->variant();
        for ($n = 1; $n <= 26; ++$n) {
            $this->entry($variantId, \sprintf('2026-10-01 10:%02d:00+00:00', $n), 1000 + $n);
        }

        $first = $this->get($variantId);
        $second = $this->get($variantId, 'after='.$this->next($first));

        self::assertCount(25, $this->items($first));
        self::assertSame(1026, $this->items($first)[0]['gross']);
        self::assertIsString($first['next']);
        self::assertSame([1001], array_column($this->items($second), 'gross'));
        self::assertNull($second['next']);
    }

    public function testPagesWithPerPageWithoutRepeatingOrSkippingEntries(): void
    {
        $variantId = $this->variant();
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->entry($variantId, '2026-10-01 10:00:00+00:00', 1000 * $n);
        }

        $seen = [];
        $query = 'perPage=2';
        for ($page = 0; $page < 5; ++$page) {
            $body = $this->get($variantId, $query);
            array_push($seen, ...array_column($this->items($body), 'gross'));
            if (null === $body['next']) {
                break;
            }
            $query = 'perPage=2&after='.$this->next($body);
        }

        // Five changes within one second: the later change comes first.
        self::assertSame([5000, 4000, 3000, 2000, 1000], $seen);
        self::assertSame(2, \count($this->items($this->get($variantId, 'perPage=2'))));
    }

    public function testResponseIsPrivateAndNotStored(): void
    {
        $this->get($this->variant());

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
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
    public function testEveryStaffRoleCanReadTheHistory(array $roles): void
    {
        $variantId = $this->variant();
        $this->entry($variantId, '2026-10-01 10:00:00+00:00', 5490);

        $body = $this->get($variantId, '', TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->items($body));
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->request($this->variant(), '', null);

        $this->assertUnauthorized();
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $this->get($this->variant(), '', TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    public function testAnUnknownVariantIsNotFound(): void
    {
        $this->entry(self::UNKNOWN_VARIANT, '2026-10-01 10:00:00+00:00', 5490);

        $this->get(self::UNKNOWN_VARIANT);

        $this->assertProblem(404);
        self::assertSame('Wariant produktu nie istnieje.', $this->json()['detail']);
        self::assertStringNotContainsString(self::UNKNOWN_VARIANT, (string) $this->client->getResponse()->getContent());
    }

    public function testTheIdOfAProductIsNotAVariantAndTheOldPathIsGone(): void
    {
        $variantId = $this->variant();
        $productId = $this->entityManager->getConnection()->fetchOne('SELECT product_id FROM catalog_product_variant WHERE id = ?', [$variantId]);
        self::assertIsString($productId);
        self::assertNotSame($variantId, $productId);
        $this->entry($productId, '2026-10-01 10:00:00+00:00', 5490);

        $this->get($productId);
        $this->assertProblem(404);
        self::assertSame('Wariant produktu nie istnieje.', $this->json()['detail']);

        $this->client->request('GET', '/api/v1/admin/products/'.$productId.'/price-history', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsThatAreNotUuids(): iterable
    {
        yield 'a word' => ['shoes'];
        yield 'a uuid with a suffix' => [self::UNKNOWN_VARIANT.'x'];
        yield 'a uuid without dashes' => [str_replace('-', '', self::UNKNOWN_VARIANT)];
    }

    #[DataProvider('idsThatAreNotUuids')]
    public function testAVariantIdThatIsNotAUuidIsNotFound(string $variantId): void
    {
        $this->get($variantId);

        $this->assertProblem(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedQueries(): iterable
    {
        yield 'zero per page' => ['perPage=0'];
        yield 'per page over 100' => ['perPage=101'];
        yield 'negative per page' => ['perPage=-1'];
        yield 'per page not a number' => ['perPage=many'];
        yield 'malformed cursor' => ['after=garbage!'];
        yield 'cursor of another shape' => ['after=aGVsbG8'];
        yield 'cursor of the product list' => ['after=eyJuIjoiQmlkb24iLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0'];
        yield 'cursor with year zero' => ['after='.self::cursor('{"t":"0000-01-01T00:00:00.000000+00:00","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
        yield 'cursor with a NUL byte in the time' => ['after='.self::cursor('{"t":"2026-10-01T10:00:00.000000+00:00\u0000","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
        yield 'cursor with a time zone offset out of range' => ['after='.self::cursor('{"t":"2026-10-01T10:00:00.000000+16:00","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
        yield 'empty cursor' => ['after='];
        yield 'cursor as an array' => ['after[]=abc'];
    }

    #[DataProvider('rejectedQueries')]
    public function testBadParametersAreRejectedWithProblemDetails(string $query): void
    {
        $variantId = $this->variant();
        $this->entry($variantId, '2026-10-01 10:00:00+00:00', 5490);

        $this->get($variantId, $query);

        $this->assertProblem(400);
        $problem = $this->json();
        self::assertSame(400, $problem['status']);
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());
    }

    public function testBoundariesOfThePageSizeAreAccepted(): void
    {
        $variantId = $this->variant();

        foreach (['perPage=1', 'perPage=100'] as $query) {
            $this->get($variantId, $query);

            self::assertResponseStatusCodeSame(200, $query);
        }
    }

    private static function cursor(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function variant(): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => TaxClassCode::PrintedBook]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product->variants()->toList()[0]->id()->toString();
    }

    /**
     * @param array<string, mixed> $attributes more columns of the history row
     */
    private function entry(string $variantId, string $changedAt, int $gross, array $attributes = []): void
    {
        PriceHistoryRecordFactory::store($this->entityManager, [
            'variantId' => $variantId,
            'changedAt' => new \DateTimeImmutable($changedAt),
            'grossAmount' => $gross,
        ] + $attributes);
    }

    /**
     * Requests the history as a staff member (an administrator unless the token says otherwise).
     *
     * @return array<mixed>
     */
    private function get(string $variantId, string $query = '', ?string $token = null): array
    {
        return $this->request($variantId, $query, 'Bearer '.($token ?? TestTokenFactory::staff(['admin'])));
    }

    /**
     * @return array<mixed>
     */
    private function request(string $variantId, string $query, ?string $authorization): array
    {
        $server = null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization];
        $this->client->request('GET', '/api/v1/admin/variants/'.$variantId.'/price-history'.('' === $query ? '' : '?'.$query), server: $server);

        return $this->json();
    }

    private function assertUnauthorized(): void
    {
        $this->assertProblem(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    private function assertProblem(int $status): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $body
     */
    private function next(array $body): string
    {
        self::assertIsString($body['next']);

        return $body['next'];
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items']);
        self::assertTrue(array_is_list($body['items']));

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
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
}
