<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pricing;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrinePromotionRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The promotions of the panel (/api/v1/admin/promotions) on PostgreSQL (shop_test, rolled back after each test).
 */
final class PromotionsEndpointTest extends WebTestCase
{
    use Factories;

    private const string TYPE_OVERLAP = 'tag:piotrp321.github.io,2026:shop/problems/promotion-overlap';
    private const string TYPE_NOT_EDITABLE = 'tag:piotrp321.github.io,2026:shop/problems/promotion-not-editable';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7000';

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
        // Only the promotions of the test are in the lists.
        $this->connection->executeStatement('DELETE FROM pricing_promotion');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writers(): iterable
    {
        yield 'admin' => ['admin'];
        yield 'marketing' => ['marketing'];
    }

    #[DataProvider('writers')]
    public function testAdminAndMarketingPlanAPromotion(string $role): void
    {
        [$a, $b] = [$this->pricedVariant(5999), $this->pricedVariant(10000)];

        $promotion = $this->send('POST', '/api/v1/admin/promotions', $this->body([$a, $b]), TestTokenFactory::staff([$role], ['preferred_username' => 'ola.'.$role]));

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($promotion['id']);
        self::assertSame('/api/v1/admin/promotions/'.$promotion['id'], $this->client->getResponse()->headers->get('Location'));
        self::assertSame(['Jesień', 20, 'planned', 2, 1, 'ola.'.$role], [$promotion['name'], $promotion['percentOff'], $promotion['status'], $promotion['variantCount'], $promotion['version'], $promotion['updatedByName']]);
        self::assertSame($this->at('+1 day'), $promotion['startsAt']);
        self::assertSame($this->at('+10 days'), $promotion['endsAt']);
        self::assertIsArray($promotion['variants']);
        self::assertSame([5999, 10000], array_column($promotion['variants'], 'regularGross'));
        self::assertSame([4800, 8000], array_column($promotion['variants'], 'promotionalGross'));
        $this->assertKeysMatchSchema($promotion, 'PromotionResponse');
        $this->assertNotStored();
        self::assertSame(
            ['created_by_id' => TestTokenFactory::STAFF_ID, 'created_by_name' => 'ola.'.$role],
            $this->connection->fetchAssociative('SELECT created_by_id, created_by_name FROM pricing_promotion WHERE id = ?', [$promotion['id']]),
        );
    }

    public function testAPromotionFromNowStartsAtTheNextSecond(): void
    {
        $promotion = $this->send('POST', '/api/v1/admin/promotions', [...$this->body([$this->pricedVariant(5999)]), 'startsAt' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($promotion['startsAt']);
        $startsAt = new \DateTimeImmutable($promotion['startsAt']);
        self::assertGreaterThan(new \DateTimeImmutable(), $startsAt);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+2 seconds'), $startsAt);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function readersAndOthers(): iterable
    {
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('readersAndOthers')]
    public function testOtherStaffMayNotChangePromotionsAndAreAskedBeforeTheBody(array $roles): void
    {
        $id = $this->storedPromotion('+1 day', '+5 days', [$this->pricedVariant(5999)]);
        $token = TestTokenFactory::staff($roles);

        $this->request('POST', '/api/v1/admin/promotions', '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('PUT', '/api/v1/admin/promotions/'.$id, '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('DELETE', '/api/v1/admin/promotions/'.$id.'?version=1', null, $token);
        $this->assertProblem(403);
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_promotion WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staff(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'marketing' => [['marketing']];
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staff')]
    public function testEveryStaffMemberReadsPromotions(array $roles): void
    {
        $id = $this->storedPromotion('+1 day', '+5 days', [$this->pricedVariant(5999)]);
        $token = TestTokenFactory::staff($roles);

        $list = $this->send('GET', '/api/v1/admin/promotions', null, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($list['items']);
        self::assertSame([$id], array_column($list['items'], 'id'));

        $this->send('GET', '/api/v1/admin/promotions/'.$id, null, $token);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function everyEndpoint(): iterable
    {
        yield 'list' => ['GET', '/api/v1/admin/promotions', null];
        yield 'plan' => ['POST', '/api/v1/admin/promotions', '{}'];
        yield 'read' => ['GET', '/api/v1/admin/promotions/'.self::UNKNOWN, null];
        yield 'revise' => ['PUT', '/api/v1/admin/promotions/'.self::UNKNOWN, '{}'];
        yield 'delete' => ['DELETE', '/api/v1/admin/promotions/'.self::UNKNOWN.'?version=1', null];
    }

    #[DataProvider('everyEndpoint')]
    public function testWithoutATokenOrWithATokenOfTheShopEveryEndpointIsUnauthorized(string $method, string $path, ?string $body): void
    {
        $this->request($method, $path, $body, null);
        $this->assertProblem(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $this->request($method, $path, $body, TestTokenFactory::customer());
        $this->assertProblem(401);
    }

    public function testTheListPagesNewestStartFirstAndFiltersByStatus(): void
    {
        $variant = $this->pricedVariant(5999);
        $ended = $this->storedPromotion('-10 days', '-5 days', [$variant]);
        $active = $this->storedPromotion('-1 day', '+1 day', [$variant]);
        $planned = $this->storedPromotion('+2 days', '+5 days', [$variant, $this->pricedVariant(100)]);

        $first = $this->send('GET', '/api/v1/admin/promotions?perPage=2');
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($first['items']);
        self::assertSame([$planned, $active], array_column($first['items'], 'id'));
        self::assertSame(['planned', 'active'], array_column($first['items'], 'status'));
        self::assertSame([2, 1], array_column($first['items'], 'variantCount'));
        $this->assertKeysMatchSchema($first, 'PromotionListResponse');
        self::assertIsArray($first['items'][0]);
        $this->assertKeysMatchSchema($first['items'][0], 'PromotionListItemResponse');
        $this->assertNotStored();
        self::assertIsString($first['next']);

        $second = $this->send('GET', '/api/v1/admin/promotions?perPage=2&after='.$first['next']);
        self::assertIsArray($second['items']);
        self::assertSame([$ended], array_column($second['items'], 'id'));
        self::assertNull($second['next']);

        foreach (['planned' => $planned, 'active' => $active, 'ended' => $ended] as $status => $id) {
            $filtered = $this->send('GET', '/api/v1/admin/promotions?status='.$status);
            self::assertIsArray($filtered['items']);
            self::assertSame([$id], array_column($filtered['items'], 'id'), $status);
        }
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function badListParameters(): iterable
    {
        $status = 'Status promocji musi być jednym z: planned, active, ended.';
        $page = 'Liczba promocji na stronie musi być od 1 do 100.';
        $cursor = 'Kursor stronicowania jest nieprawidłowy.';

        yield 'an unknown status' => ['status=running', 'status', $status];
        yield 'a page of 0' => ['perPage=0', 'perPage', $page];
        yield 'a page of 101' => ['perPage=101', 'perPage', $page];
        yield 'a page that is no number' => ['perPage=many', 'perPage', null];
        yield 'a cursor nobody handed out' => ['after=garbage', 'after', $cursor];
        yield 'a cursor that is no base64' => ['after=%25%25%25', 'after', $cursor];
        yield 'an empty cursor' => ['after=', 'after', $cursor];
        yield 'a cursor past the maximum length' => ['after='.str_repeat('a', 2049), 'after', $cursor];
    }

    #[DataProvider('badListParameters')]
    public function testBadListParametersAreABadRequest(string $query, string $field, ?string $title): void
    {
        $this->send('GET', '/api/v1/admin/promotions?'.$query);

        $problem = $this->assertProblem(400);
        self::assertIsArray($problem['violations']);
        self::assertSame([$field], array_column($problem['violations'], 'propertyPath'));
        if (null !== $title) {
            self::assertSame([$title], array_column($problem['violations'], 'title'));
        }
    }

    public function testAPromotionShowsItsVariantsAlsoOneDeletedFromTheCatalog(): void
    {
        $variant = $this->pricedVariant(5999);
        $id = $this->storedPromotion('-1 day', '+1 day', [$variant, self::UNKNOWN]);

        $promotion = $this->send('GET', '/api/v1/admin/promotions/'.$id);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('active', $promotion['status']);
        self::assertIsArray($promotion['variants']);
        $productId = $this->connection->fetchOne('SELECT product_id FROM catalog_product_variant WHERE id = ?', [$variant]);
        self::assertEqualsCanonicalizing(
            [
                ['variantId' => $variant, 'productId' => $productId, 'productName' => $this->productName($variant), 'label' => '', 'regularGross' => 5999, 'promotionalGross' => 4800],
                ['variantId' => self::UNKNOWN, 'productId' => null, 'productName' => null, 'label' => null, 'regularGross' => null, 'promotionalGross' => null],
            ],
            $promotion['variants'],
        );
        self::assertIsArray($promotion['variants'][0]);
        $this->assertKeysMatchSchema($promotion['variants'][0], 'PromotionVariantResponse');
        $this->assertNotStored();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPromotions(): iterable
    {
        yield 'an unknown id' => ['/api/v1/admin/promotions/'.self::UNKNOWN];
        yield 'an id that is no UUID' => ['/api/v1/admin/promotions/promotion-1'];
    }

    #[DataProvider('unknownPromotions')]
    public function testAnUnknownPromotionIsNotFound(string $path): void
    {
        $this->send('GET', $path);
        $this->assertProblem(404);
        $this->send('PUT', $path, [...$this->body([$this->pricedVariant(5999)]), 'version' => 1]);
        $this->assertProblem(404);
        $this->send('DELETE', $path.'?version=1');
        $this->assertProblem(404);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no name' => [['name' => '__missing__'], 'name'];
        yield 'an empty name' => [['name' => '  '], 'name'];
        yield 'a name of 101 characters' => [['name' => str_repeat('a', 101)], 'name'];
        yield 'a name with a new line' => [['name' => "Jesień\nw górach"], 'name'];
        yield 'a name of no-break spaces' => [['name' => "\u{A0}\u{A0}"], 'name'];
        yield 'a name of an em space and an ideographic space' => [['name' => "\u{2003}\u{3000}"], 'name'];
        yield 'a name of a zero width space' => [['name' => "\u{200B}"], 'name'];
        yield 'a name with a line separator' => [['name' => "a\u{2028}b"], 'name'];
        yield 'a name that is no string' => [['name' => 12], 'name'];
        yield 'a percent of 0' => [['percentOff' => 0], 'percentOff'];
        yield 'a percent of 91' => [['percentOff' => 91], 'percentOff'];
        yield 'a percent in a string' => [['percentOff' => '20'], 'percentOff'];
        yield 'a fractional percent' => [['percentOff' => 20.5], 'percentOff'];
        yield 'a percent of 20.0' => ['{"name": "Jesień", "percentOff": 20.0, "startsAt": null, "endsAt": "2099-01-01T00:00:00Z", "variantIds": ["'.self::UNKNOWN.'"]}', 'percentOff'];
        yield 'no start key' => [['startsAt' => '__missing__'], 'startsAt'];
        yield 'a start without a time zone' => [['startsAt' => '2030-01-01T10:00:00'], 'startsAt'];
        yield 'a start that is a date only' => [['startsAt' => '2030-01-01'], 'startsAt'];
        yield 'an impossible start' => [['startsAt' => '2030-02-31T10:00:00Z'], 'startsAt'];
        yield 'a start that is a number' => [['startsAt' => 1893456000], 'startsAt'];
        yield 'a start past the year 9999 in UTC' => [['startsAt' => '9999-12-31T23:00:00-05:00', 'endsAt' => '9999-12-31T23:30:00-05:00'], 'startsAt'];
        yield 'an end past the year 9999 in UTC' => [['startsAt' => '9999-12-31T20:00:00Z', 'endsAt' => '9999-12-31T23:30:00-05:00'], 'endsAt'];
        yield 'no end' => [['endsAt' => null], 'endsAt'];
        yield 'an end in the past' => [['endsAt' => '-1 day'], 'endsAt'];
        yield 'an end before the start' => [['startsAt' => '+5 days', 'endsAt' => '+4 days'], 'endsAt'];
        yield 'a period of 91 days' => [['startsAt' => '+1 day', 'endsAt' => '+92 days'], 'endsAt'];
        yield 'no variants' => [['variantIds' => []], 'variantIds'];
        yield 'variants as an object' => [['variantIds' => ['a' => '$a']], 'variantIds'];
        yield 'a variant twice' => [['variantIds' => ['$a', '$a']], 'variantIds'];
        yield 'a variant id that is no UUID' => [['variantIds' => ['$a', 'shoes']], 'variantIds[1]'];
        yield 'a variant id that is no string' => [['variantIds' => [12]], 'variantIds[0]'];
        yield 'variants that are no list' => [['variantIds' => '$a'], 'variantIds'];
        yield 'a list body' => ['[1, 2]', ''];
        yield 'an empty body' => ['', ''];
    }

    /**
     * @param array<string, mixed>|string $change
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsUnprocessableAndPlansNothing(array|string $change, string $field): void
    {
        $a = $this->pricedVariant(5999);
        if (\is_string($change)) {
            $this->request('POST', '/api/v1/admin/promotions', $change, TestTokenFactory::staff(['marketing']));
        } else {
            $this->send('POST', '/api/v1/admin/promotions', $this->resolve([...$this->body([$a]), ...$change], $a));
        }

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        if ('' !== $field) {
            self::assertContains($field, array_column($problem['violations'], 'propertyPath'));
        }
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_promotion'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notVariantIds(): iterable
    {
        yield 'a string' => ['x'];
        yield 'a number' => [12];
        yield 'an object' => [new \stdClass()];
    }

    #[DataProvider('notVariantIds')]
    public function testAListPastTheLimitWithAnElementThatIsNoUuidIsUnprocessable(mixed $element): void
    {
        $this->send('POST', '/api/v1/admin/promotions', $this->body($this->tooManyVariantIds($element)));

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertContains('variantIds', array_column($problem['violations'], 'propertyPath'));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_promotion'));
    }

    public function testARevisePastTheLimitWithAnElementThatIsNoUuidIsUnprocessable(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body($this->tooManyVariantIds('x')), 'version' => 1]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertContains('variantIds', array_column($problem['violations'], 'propertyPath'));
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_promotion WHERE id = ?', [$id]));
    }

    public function testAVariantUnknownToCatalogOrWithoutAPriceIsRefusedAtItsPlace(): void
    {
        $priced = $this->pricedVariant(5999);
        $unpriced = $this->variant();

        $this->send('POST', '/api/v1/admin/promotions', $this->body([$priced, self::UNKNOWN, $unpriced]));

        $problem = $this->assertProblem(422);
        self::assertSame(
            [
                ['propertyPath' => 'variantIds[1]', 'title' => 'Wariant produktu nie istnieje.'],
                ['propertyPath' => 'variantIds[2]', 'title' => 'Wariant bez ceny nie może być w promocji.'],
            ],
            $problem['violations'],
        );
    }

    public function testAnOverlappingPromotionOnACommonVariantIsAConflictListingTheVariants(): void
    {
        [$a, $b, $c] = [$this->pricedVariant(5999), $this->pricedVariant(100), $this->pricedVariant(200)];
        $this->storedPromotion('+5 days', '+20 days', [$b, $c]);

        $this->send('POST', '/api/v1/admin/promotions', $this->body([$a, $c, $b]));

        $problem = $this->assertProblem(409);
        self::assertSame(self::TYPE_OVERLAP, $problem['type']);
        self::assertSame('Część wariantów jest już w innej promocji w tym okresie.', $problem['detail']);
        $expected = [$b, $c];
        sort($expected);
        self::assertSame($expected, $problem['conflictingVariantIds']);
        $this->assertKeysWithinSchema($problem, 'PricingConflictProblem');
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM pricing_promotion'));
    }

    public function testARevisedPlannedPromotionChangesEverything(): void
    {
        [$a, $b] = [$this->pricedVariant(5999), $this->pricedVariant(10000)];
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);

        $promotion = $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body([$b]), 'name' => 'Zima', 'percentOff' => 50, 'version' => 1], TestTokenFactory::staff(['admin'], ['preferred_username' => 'bartek']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Zima', 50, 2, 'bartek'], [$promotion['name'], $promotion['percentOff'], $promotion['version'], $promotion['updatedByName']]);
        self::assertIsArray($promotion['variants']);
        self::assertSame([$b], array_column($promotion['variants'], 'variantId'));
        self::assertSame([5000], array_column($promotion['variants'], 'promotionalGross'));
        $this->assertKeysMatchSchema($promotion, 'PromotionResponse');
        $this->assertNotStored();
    }

    public function testAnActivePromotionChangesItsNameAndEndAndEndsNow(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('-1 day', '+5 days', [$a]);
        $startsAt = $this->send('GET', '/api/v1/admin/promotions/'.$id)['startsAt'];

        $promotion = $this->send('PUT', '/api/v1/admin/promotions/'.$id, ['name' => 'Krótsza', 'percentOff' => 20, 'startsAt' => $startsAt, 'endsAt' => $this->at('now'), 'variantIds' => [$a], 'version' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Krótsza', 'active', 2], [$promotion['name'], $promotion['status'], $promotion['version']]);
        self::assertIsString($promotion['endsAt']);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+2 seconds'), new \DateTimeImmutable($promotion['endsAt']));
    }

    public function testAnActivePromotionRefusesAnotherPercent(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('-1 day', '+5 days', [$a]);
        $startsAt = $this->send('GET', '/api/v1/admin/promotions/'.$id)['startsAt'];

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, ['name' => 'Jesień', 'percentOff' => 30, 'startsAt' => $startsAt, 'endsAt' => $this->at('+5 days'), 'variantIds' => [$a], 'version' => 1]);

        $problem = $this->assertProblem(409);
        self::assertSame(self::TYPE_NOT_EDITABLE, $problem['type']);
        self::assertSame('Po rozpoczęciu promocji można zmienić tylko jej nazwę i koniec.', $problem['detail']);
        $this->assertKeysWithinSchema($problem, 'PricingConflictProblem');
    }

    public function testAReviseOfAnotherVersionIsAConflict(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body([$a]), 'version' => 2]);

        $problem = $this->assertProblem(409);
        self::assertSame('about:blank', $problem['type']);
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_promotion WHERE id = ?', [$id]));
    }

    public function testAMovedEndOverlappingAnotherPromotionIsAConflict(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);
        $this->storedPromotion('+5 days', '+10 days', [$a]);

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body([$a]), 'endsAt' => $this->at('+6 days'), 'version' => 1]);

        $problem = $this->assertProblem(409);
        self::assertSame(self::TYPE_OVERLAP, $problem['type']);
        self::assertSame([$a], $problem['conflictingVariantIds']);
    }

    public function testAReviseToAMomentPastTheYear9999InUtcIsUnprocessable(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body([$a]), 'startsAt' => '9999-12-31T20:00:00Z', 'endsAt' => '9999-12-31T23:30:00-05:00', 'version' => 1]);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertContains('endsAt', array_column($problem['violations'], 'propertyPath'));
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_promotion WHERE id = ?', [$id]));
    }

    public function testAPromotionEndingInTheLastSecondOfTheYear9999IsPlannedAndListed(): void
    {
        $a = $this->pricedVariant(5999);

        $this->send('POST', '/api/v1/admin/promotions', [...$this->body([$a]), 'startsAt' => '9999-12-31T20:00:00Z', 'endsAt' => '9999-12-31T23:59:59Z']);
        self::assertResponseStatusCodeSame(201);

        $this->send('GET', '/api/v1/admin/promotions');
        self::assertResponseIsSuccessful();
    }

    public function testAReviseWithAnInvalidBodyIsUnprocessable(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);

        foreach ([[...$this->body([$a])], [...$this->body([$a]), 'version' => '1'], [...$this->body([$a, self::UNKNOWN]), 'version' => 1]] as $body) {
            $this->send('PUT', '/api/v1/admin/promotions/'.$id, $body);
            $this->assertProblem(422);
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_promotion WHERE id = ?', [$id]));
    }

    public function testAReviseWithANameOfUnicodeSpacesIsUnprocessableAndATrimmedNameIsStored(): void
    {
        $a = $this->pricedVariant(5999);
        $id = $this->storedPromotion('+1 day', '+5 days', [$a]);

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body([$a]), 'name' => "\u{A0}\u{3000}", 'version' => 1]);
        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertContains('name', array_column($problem['violations'], 'propertyPath'));
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_promotion WHERE id = ?', [$id]));

        $this->send('PUT', '/api/v1/admin/promotions/'.$id, [...$this->body([$a]), 'name' => "\u{A0}Wiosna\u{3000}", 'version' => 1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Wiosna', $this->connection->fetchOne('SELECT name FROM pricing_promotion WHERE id = ?', [$id]));
    }

    public function testAPlannedPromotionIsDeleted(): void
    {
        $id = $this->storedPromotion('+1 day', '+5 days', [$this->pricedVariant(5999)]);

        $this->request('DELETE', '/api/v1/admin/promotions/'.$id.'?version=1', null, TestTokenFactory::staff(['marketing']));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_promotion WHERE id = ?', [$id]));
    }

    public function testAnActivePromotionOrAnotherVersionIsNotDeleted(): void
    {
        $active = $this->storedPromotion('-1 day', '+5 days', [$this->pricedVariant(5999)]);
        $planned = $this->storedPromotion('+1 day', '+5 days', [$this->pricedVariant(5999)]);

        $this->send('DELETE', '/api/v1/admin/promotions/'.$active.'?version=1');
        self::assertSame(self::TYPE_NOT_EDITABLE, $this->assertProblem(409)['type']);
        $this->send('DELETE', '/api/v1/admin/promotions/'.$planned.'?version=2');
        self::assertSame('about:blank', $this->assertProblem(409)['type']);
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM pricing_promotion'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badVersions(): iterable
    {
        yield 'no version' => [''];
        yield 'a version that is no number' => ['?version=one'];
        yield 'a version of 0' => ['?version=0'];
        yield 'a version that is letters' => ['?version=abc'];
    }

    #[DataProvider('badVersions')]
    public function testADeleteWithoutAVersionIsABadRequest(string $query): void
    {
        $id = $this->storedPromotion('+1 day', '+5 days', [$this->pricedVariant(5999)]);

        $this->send('DELETE', '/api/v1/admin/promotions/'.$id.$query);

        $problem = $this->assertProblem(400);
        self::assertIsArray($problem['violations']);
        self::assertSame(['version'], array_column($problem['violations'], 'propertyPath'));
        self::assertSame(['Wersja musi być dodatnią liczbą całkowitą.'], array_column($problem['violations'], 'title'));
    }

    /**
     * @return list<mixed> one element over the limit of the variants, the last of them the given one
     */
    private function tooManyVariantIds(mixed $element): array
    {
        $ids = array_fill(0, 1000, self::UNKNOWN);
        $ids[] = $element;

        return $ids;
    }

    /**
     * @param list<mixed> $variantIds
     *
     * @return array<string, mixed>
     */
    private function body(array $variantIds): array
    {
        return ['name' => 'Jesień', 'percentOff' => 20, 'startsAt' => $this->at('+1 day'), 'endsAt' => $this->at('+10 days'), 'variantIds' => $variantIds];
    }

    /**
     * Relative moments (`+1 day`) become RFC 3339 times, `$a` the variant, `__missing__` drops the key.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function resolve(array $body, string $variant): array
    {
        foreach (['startsAt', 'endsAt'] as $key) {
            if (\is_string($body[$key] ?? null) && 1 === preg_match('/^([+-]\d+ days?|now)$/', $body[$key])) {
                $body[$key] = $this->at($body[$key]);
            }
        }
        if (\is_array($body['variantIds'] ?? null)) {
            $body['variantIds'] = array_map(static fn (mixed $id): mixed => '$a' === $id ? $variant : $id, $body['variantIds']);
        } elseif ('$a' === ($body['variantIds'] ?? null)) {
            $body['variantIds'] = $variant;
        }

        return array_filter($body, static fn (mixed $value): bool => '__missing__' !== $value);
    }

    /**
     * The whole second of a moment relative to now, in UTC (RFC 3339).
     */
    private function at(string $relative): string
    {
        return new \DateTimeImmutable('@'.new \DateTimeImmutable($relative)->getTimestamp())->format(\DATE_ATOM);
    }

    /**
     * A promotion stored as if planned a month ago, so it may have started already.
     *
     * @param list<string> $variants
     */
    private function storedPromotion(string $startsAt, string $endsAt, array $variants): string
    {
        $id = Uuid::v7()->toRfc4122();
        new DoctrinePromotionRepository($this->entityManager)->save(Promotion::plan(
            PromotionId::fromString($id), 'Jesień', PercentOff::of(20), new \DateTimeImmutable($this->at($startsAt)), new \DateTimeImmutable($this->at($endsAt)),
            array_map(VariantId::fromString(...), $variants), null, new \DateTimeImmutable('-30 days'),
        ));
        $this->entityManager->clear();

        return $id;
    }

    private function variant(): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => TaxClassCode::Standard]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product->variants()->toList()[0]->id()->toString();
    }

    private function pricedVariant(int $gross): string
    {
        $variantId = $this->variant();
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $variantId, 'gross' => $gross, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        $this->entityManager->clear();

        return $variantId;
    }

    private function productName(string $variantId): string
    {
        $name = $this->connection->fetchOne('SELECT p.name FROM catalog_product p JOIN catalog_product_variant v ON v.product_id = p.id WHERE v.id = ?', [$variantId]);
        self::assertIsString($name);

        return $name;
    }

    /**
     * Sends JSON as a staff member (marketing unless the token says otherwise).
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        return $this->request($method, $path, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR), $token ?? TestTokenFactory::staff(['marketing']));
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
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($status, $problem['status']);
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());

        return $problem;
    }

    private function assertNotStored(): void
    {
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    /**
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        self::assertEqualsCanonicalizing(array_keys($this->schemaProperties($schemaName)), array_keys($object), $schemaName.' keys must match the schema.');
    }

    /**
     * @param array<mixed> $object
     */
    private function assertKeysWithinSchema(array $object, string $schemaName): void
    {
        $properties = $this->schemaProperties($schemaName);
        self::assertSame([], array_values(array_diff(array_keys($object), array_keys($properties))), $schemaName.' must describe every key.');
        self::assertIsArray($properties['type']);
        self::assertIsArray($properties['type']['enum']);
        self::assertContains($object['type'], $properties['type']['enum']);
    }

    /**
     * @return array<mixed>
     */
    private function schemaProperties(string $schemaName): array
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName] ?? null;
        self::assertIsArray($schema, $schemaName.' must be in openapi-admin.json.');
        self::assertIsArray($schema['properties']);

        return $schema['properties'];
    }
}
