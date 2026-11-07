<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pricing;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineVariantPriceRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Pricing\InterleavingVariantPriceRepository;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * PUT /api/v1/admin/variants/{variantId}/price on PostgreSQL (shop_test, rolled back after each test).
 */
final class ChangePriceEndpointTest extends WebTestCase
{
    use Factories;

    private const string UNKNOWN_VARIANT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

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

    public function testChangesTheGrossAndReturnsTheSavedPrice(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $price = $this->put($variantId, ['gross' => 5990, 'version' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['gross' => 5990, 'net' => 5705, 'vat' => 285, 'vatRate' => 500, 'currency' => 'PLN', 'version' => 2, 'vatRateOutdated' => false],
            array_diff_key($price, ['updatedAt' => 1]),
        );
        self::assertIsString($price['updatedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $price['updatedAt']);
        $this->assertKeysMatchSchema($price, 'ListedPriceResponse');
        self::assertSame(['gross_amount' => 5990, 'version' => 2], $this->storedPrice($variantId));
        self::assertCount(1, $this->history($variantId));
    }

    public function testTheFirstPriceOfAVariantIsSetWithoutAVersion(): void
    {
        $variantId = $this->variant(TaxClassCode::EnergyGel);

        $price = $this->put($variantId, ['gross' => 1080, 'version' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['gross' => 1080, 'net' => 1000, 'vat' => 80, 'vatRate' => 800, 'currency' => 'PLN', 'version' => 1, 'vatRateOutdated' => false],
            array_diff_key($price, ['updatedAt' => 1]),
        );
        self::assertSame(['gross_amount' => 1080, 'version' => 1], $this->storedPrice($variantId));
    }

    public function testTheSameAmountChangesNeitherTheVersionNorTheHistory(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $price = $this->put($variantId, ['gross' => 5490, 'version' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $price['version']);
        self::assertSame(5490, $price['gross']);
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
        self::assertSame([], $this->history($variantId));
    }

    public function testTheActorOfTheHistoryEntryComesFromTheTokenNeverFromTheBody(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put(
            $variantId,
            ['gross' => 5990, 'version' => 1, 'actorId' => 'forged-id', 'actorName' => 'Forged Name', 'source' => 'seed'],
            TestTokenFactory::staff(['admin'], ['preferred_username' => 'anna.admin']),
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['source' => 'staff', 'actor_id' => TestTokenFactory::STAFF_ID, 'actor_name' => 'anna.admin', 'gross_amount' => 5990]],
            $this->history($variantId),
        );
    }

    public function testResponseIsPrivateAndNotStored(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put($variantId, ['gross' => 5990, 'version' => 1]);

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolesOtherThanAdmin(): iterable
    {
        yield 'marketing' => [['marketing']];
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'all roles but admin' => [['marketing', 'support', 'warehouse']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesOtherThanAdmin')]
    public function testOnlyAnAdministratorMayChangeAPrice(array $roles): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put($variantId, ['gross' => 5990, 'version' => 1], TestTokenFactory::staff($roles));

        $this->assertProblem(403);
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
        self::assertSame([], $this->history($variantId));
    }

    public function testAuthorizationIsCheckedBeforeTheBodyAndTheVariant(): void
    {
        $this->put(self::UNKNOWN_VARIANT, ['gross' => 'garbage'], TestTokenFactory::staff(['marketing']));

        $this->assertProblem(403);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->request($variantId, json_encode(['gross' => 5990, 'version' => 1], \JSON_THROW_ON_ERROR), null);

        $this->assertUnauthorized();
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put($variantId, ['gross' => 5990, 'version' => 1], TestTokenFactory::customer());

        $this->assertUnauthorized();
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
    }

    public function testAnUnknownVariantIsNotFound(): void
    {
        $this->put(self::UNKNOWN_VARIANT, ['gross' => 5990, 'version' => null]);

        $problem = $this->assertProblem(404);
        self::assertSame('Wariant produktu nie istnieje.', $problem['detail']);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_variant_price WHERE variant_id = ?', [self::UNKNOWN_VARIANT]));
    }

    public function testTheIdOfAProductIsNotAVariantAndTheOldPathIsGone(): void
    {
        $variantId = $this->variant(TaxClassCode::Standard);
        $productId = $this->connection->fetchOne('SELECT product_id FROM catalog_product_variant WHERE id = ?', [$variantId]);
        self::assertIsString($productId);
        self::assertNotSame($variantId, $productId);

        $this->put($productId, ['gross' => 5990, 'version' => null]);
        self::assertSame('Wariant produktu nie istnieje.', $this->assertProblem(404)['detail']);

        $this->client->request('PUT', '/api/v1/admin/products/'.$productId.'/price', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])], content: '{"gross": 5990, "version": null}');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_variant_price'));
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
        $this->put($variantId, ['gross' => 5990, 'version' => null]);

        $this->assertProblem(404);
    }

    public function testAnUppercaseUuidNamesTheSameVariant(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put(strtoupper($variantId), ['gross' => 5990, 'version' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['gross_amount' => 5990, 'version' => 2], $this->storedPrice($variantId));
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function staleVersions(): iterable
    {
        yield 'null for an existing price' => [null];
        yield 'an older version' => [1];
        yield 'a newer version' => [3];
    }

    #[DataProvider('staleVersions')]
    public function testAStaleVersionIsAConflictAndOverwritesNothing(?int $version): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);
        $this->put($variantId, ['gross' => 5990, 'version' => 1]);
        self::assertResponseStatusCodeSame(200);

        $this->put($variantId, ['gross' => 6490, 'version' => $version]);

        $problem = $this->assertProblem(409);
        self::assertSame('The price was modified concurrently. Reload it and try again.', $problem['detail']);
        self::assertSame(['gross_amount' => 5990, 'version' => 2], $this->storedPrice($variantId));
        self::assertCount(1, $this->history($variantId));
    }

    public function testAVersionForAVariantWithoutAPriceIsAConflict(): void
    {
        $variantId = $this->variant(TaxClassCode::Standard);

        $this->put($variantId, ['gross' => 5990, 'version' => 1]);

        $this->assertProblem(409);
        self::assertNull($this->storedPrice($variantId));
    }

    public function testAChangeSavedConcurrentlyAfterTheReadIsAConflict(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);
        $this->client->disableReboot();
        // Another change moves the row to version 2 after this request has read version 1 and before it writes, so the
        // versioned UPDATE finds no row at version 1. The competing UPDATE runs on the same connection, inside the
        // command's transaction, and is rolled back with it: the row ends as it was.
        self::getContainer()->set(VariantPriceRepository::class, new InterleavingVariantPriceRepository(
            new DoctrineVariantPriceRepository($this->entityManager, new RecordingDomainEventPublisher()),
            function () use ($variantId): void {
                $this->connection->executeStatement(
                    'UPDATE pricing_variant_price SET gross_amount = 7000, version = version + 1 WHERE variant_id = ?',
                    [$variantId],
                );
            },
        ));

        $this->put($variantId, ['gross' => 5990, 'version' => 1]);

        $problem = $this->assertProblem(409);
        self::assertSame('The price was modified concurrently. Reload it and try again.', $problem['detail']);
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
        self::assertSame([], $this->history($variantId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'zero' => ['{"gross": 0, "version": 1}'];
        yield 'negative' => ['{"gross": -100, "version": 1}'];
        yield 'over 999 999.99 zł' => ['{"gross": 100000000, "version": 1}'];
        yield 'beyond the int range' => ['{"gross": 99999999999999999999, "version": 1}'];
        yield 'a fraction of a grosz' => ['{"gross": 12.5, "version": 1}'];
        yield 'a whole number as a float' => ['{"gross": 100.0, "version": 1}'];
        yield 'a number in a string' => ['{"gross": "100", "version": 1}'];
        yield 'a boolean' => ['{"gross": true, "version": 1}'];
        yield 'a word' => ['{"gross": "abc", "version": 1}'];
        yield 'an object' => ['{"gross": {"amount": 100}, "version": 1}'];
        yield 'null gross' => ['{"gross": null, "version": 1}'];
        yield 'an array' => ['{"gross": [100], "version": 1}'];
        yield 'no gross' => ['{"version": 1}'];
        yield 'no version' => ['{"gross": 5990}'];
        yield 'a version in a string' => ['{"gross": 5990, "version": "1"}'];
        yield 'a fractional version' => ['{"gross": 5990, "version": 1.5}'];
        yield 'a boolean version' => ['{"gross": 5990, "version": false}'];
        yield 'an empty object' => ['{}'];
        yield 'an empty body' => [''];
        yield 'a list' => ['[5990, 1]'];
        yield 'a bare number' => ['5990'];
    }

    #[DataProvider('rejectedBodies')]
    public function testAnInvalidAmountOrTypeIsUnprocessableAndChangesNothing(string $body): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->request($variantId, $body, 'Bearer '.TestTokenFactory::staff(['admin']));

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertNotEmpty($problem['violations']);
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
        self::assertSame([], $this->history($variantId));
    }

    public function testTheViolationOfTheAmountNamesTheField(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put($variantId, ['gross' => 0, 'version' => 1]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(['gross'], array_column($problem['violations'], 'propertyPath'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function amountsOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-100];
        yield 'above the maximum' => [GrossPrice::MAX + 1];
    }

    /**
     * The panel shows the title of the violation to a staff member as it is: in Polish, in złoty, without the amount
     * that was sent. The range in the message is built here from the limits of the domain, so it cannot drift from them.
     */
    #[DataProvider('amountsOutOfRange')]
    public function testTheViolationOfTheRangeSaysTheLimitsInZloty(int $gross): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put($variantId, ['gross' => $gross, 'version' => 1]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        $zloty = static fn (int $grosze): string => number_format($grosze / 100, 2, ',', ' ');
        self::assertSame(
            [\sprintf('Cena brutto musi wynosić od %s zł do %s zł.', $zloty(GrossPrice::MIN), $zloty(GrossPrice::MAX))],
            array_column($problem['violations'], 'title'),
        );
    }

    public function testTheLimitsOfTheAmountAreAccepted(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->put($variantId, ['gross' => 1, 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        $this->put($variantId, ['gross' => 99_999_999, 'version' => 2]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['gross_amount' => 99_999_999, 'version' => 3], $this->storedPrice($variantId));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->request($variantId, '{"gross": 5990,', 'Bearer '.TestTokenFactory::staff(['admin']));

        $this->assertProblem(400);
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
    }

    public function testAFormBodyIsNotAccepted(): void
    {
        $variantId = $this->pricedVariant(TaxClassCode::PrintedBook, 5490, 500);

        $this->request($variantId, 'gross=5990&version=1', 'Bearer '.TestTokenFactory::staff(['admin']), 'application/x-www-form-urlencoded');

        $this->assertProblem(415);
        self::assertSame(['gross_amount' => 5490, 'version' => 1], $this->storedPrice($variantId));
    }

    private function variant(TaxClassCode $taxClass): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => $taxClass]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product->variants()->toList()[0]->id()->toString();
    }

    private function pricedVariant(TaxClassCode $taxClass, int $gross, int $basisPoints): string
    {
        $variantId = $this->variant($taxClass);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $variantId, 'gross' => $gross, 'taxClass' => $taxClass->value, 'vatRateBp' => $basisPoints]);
        // The request reads the price with its own entity manager state, as a real one would.
        $this->entityManager->clear();

        return $variantId;
    }

    /**
     * Changes the price as a staff member (an administrator unless the token says otherwise).
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function put(string $variantId, array $body, ?string $token = null): array
    {
        return $this->request($variantId, json_encode($body, \JSON_THROW_ON_ERROR), 'Bearer '.($token ?? TestTokenFactory::staff(['admin'])));
    }

    /**
     * @return array<mixed>
     */
    private function request(string $variantId, string $body, ?string $authorization, string $contentType = 'application/json'): array
    {
        $server = ['CONTENT_TYPE' => $contentType];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $this->client->request('PUT', '/api/v1/admin/variants/'.$variantId.'/price', server: $server, content: $body);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedPrice(string $variantId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT gross_amount, version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]);

        return false === $row ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(string $variantId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT source, actor_id, actor_name, gross_amount FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id',
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

    private function assertUnauthorized(): void
    {
        $this->assertProblem(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
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
}
