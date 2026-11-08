<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pricing;

use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeUsageRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The discount codes of the panel (/api/v1/admin/discount-codes) on PostgreSQL (shop_test, rolled back after each
 * test).
 */
final class DiscountCodesEndpointTest extends WebTestCase
{
    use Factories;

    private const string TYPE_TAKEN = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-taken';
    private const string TYPE_NOT_EDITABLE = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-not-editable';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8000';
    /** The start of a code stored with the defaults: a fixed moment, so a revise sends exactly the stored one. */
    private const string STARTED = '2020-01-01T00:00:00+00:00';

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
        // Only the codes of the test are in the lists.
        $this->connection->executeStatement('DELETE FROM pricing_discount_code_usage');
        $this->connection->executeStatement('DELETE FROM pricing_discount_code');
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
    public function testAdminAndMarketingCreateACode(string $role): void
    {
        $body = [...$this->body(), 'code' => ' witaj10 '];
        $code = $this->send('POST', '/api/v1/admin/discount-codes', $body, TestTokenFactory::staff([$role], ['preferred_username' => 'ola.'.$role]));

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($code['id']);
        self::assertSame('/api/v1/admin/discount-codes/'.$code['id'], $this->client->getResponse()->headers->get('Location'));
        self::assertSame(
            ['id' => $code['id'], 'code' => 'WITAJ10', 'kind' => 'percent', 'value' => 10, 'minimumOrderGross' => null, 'startsAt' => $body['startsAt'], 'endsAt' => null, 'usageLimit' => null, 'activeUses' => 0, 'enabled' => true, 'status' => 'active', 'version' => 1],
            $code,
        );
        $this->assertKeysMatchSchema($code, 'DiscountCodeResponse');
        $this->assertNotStored();
        self::assertSame(
            ['created_by_id' => TestTokenFactory::STAFF_ID, 'created_by_name' => 'ola.'.$role],
            $this->connection->fetchAssociative('SELECT created_by_id, created_by_name FROM pricing_discount_code WHERE id = ?', [$code['id']]),
        );
    }

    public function testAnAmountCodeWithAllItsValuesIsCreated(): void
    {
        $code = $this->send('POST', '/api/v1/admin/discount-codes', [
            'code' => 'MINUS50', 'kind' => 'amount', 'value' => 5000, 'minimumOrderGross' => 30000,
            'startsAt' => '2099-01-01T00:00:00+01:00', 'endsAt' => '2099-02-01T00:00:00.500+01:00', 'usageLimit' => 100, 'enabled' => false,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            ['MINUS50', 'amount', 5000, 30000, '2098-12-31T23:00:00+00:00', '2099-01-31T23:00:00+00:00', 100, false, 'disabled'],
            [$code['code'], $code['kind'], $code['value'], $code['minimumOrderGross'], $code['startsAt'], $code['endsAt'], $code['usageLimit'], $code['enabled'], $code['status']],
        );
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
    public function testOtherStaffMayNotChangeCodesAndAreAskedBeforeTheBody(array $roles): void
    {
        $id = $this->storedCode();
        $token = TestTokenFactory::staff($roles);

        $this->request('POST', '/api/v1/admin/discount-codes', '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('PUT', '/api/v1/admin/discount-codes/'.$id, '{"garbage"', $token);
        $this->assertProblem(403);
        $this->request('PUT', '/api/v1/admin/discount-codes/'.self::UNKNOWN, '{"garbage"', $token);
        $this->assertProblem(403);
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM pricing_discount_code'));
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_discount_code WHERE id = ?', [$id]));
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
    public function testEveryStaffMemberReadsCodes(array $roles): void
    {
        $id = $this->storedCode(['usageLimit' => 10]);
        $this->use($id, 2);
        $token = TestTokenFactory::staff($roles);

        $list = $this->send('GET', '/api/v1/admin/discount-codes', null, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($list['items']);
        self::assertSame([$id], array_column($list['items'], 'id'));
        self::assertSame([2], array_column($list['items'], 'activeUses'));

        $code = $this->send('GET', '/api/v1/admin/discount-codes/'.$id, null, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([2, 10], [$code['activeUses'], $code['usageLimit']]);
        $this->assertKeysMatchSchema($code, 'DiscountCodeResponse');
        $this->assertNotStored();
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function everyEndpoint(): iterable
    {
        yield 'list' => ['GET', '/api/v1/admin/discount-codes', null];
        yield 'create' => ['POST', '/api/v1/admin/discount-codes', '{}'];
        yield 'read' => ['GET', '/api/v1/admin/discount-codes/'.self::UNKNOWN, null];
        yield 'revise' => ['PUT', '/api/v1/admin/discount-codes/'.self::UNKNOWN, '{}'];
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

    public function testAPageSizeWithLeadingZerosIsANumber(): void
    {
        $this->storedCode();

        $list = $this->send('GET', '/api/v1/admin/discount-codes?perPage=001');

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
    }

    public function testTheListPagesNewestFirst(): void
    {
        $oldest = $this->storedCode(['createdAt' => '-3 days']);
        $middle = $this->storedCode(['createdAt' => '-2 days']);
        $newest = $this->storedCode(['createdAt' => '-1 day']);

        $first = $this->send('GET', '/api/v1/admin/discount-codes?perPage=2');
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($first['items']);
        self::assertSame([$newest, $middle], array_column($first['items'], 'id'));
        $this->assertKeysMatchSchema($first, 'DiscountCodeListResponse');
        self::assertIsArray($first['items'][0]);
        $this->assertKeysMatchSchema($first['items'][0], 'DiscountCodeResponse');
        $this->assertNotStored();
        self::assertIsString($first['next']);

        $second = $this->send('GET', '/api/v1/admin/discount-codes?perPage=2&after='.$first['next']);
        self::assertIsArray($second['items']);
        self::assertSame([$oldest], array_column($second['items'], 'id'));
        self::assertNull($second['next']);
    }

    public function testTheListFiltersByStatusAndByTheBeginningOfTheCode(): void
    {
        $disabled = $this->storedCode(['code' => 'ZIMA20', 'enabled' => false]);
        $planned = $this->storedCode(['code' => 'WIOSNA', 'startsAt' => '+1 day']);
        $active = $this->storedCode(['code' => 'WITAJ10']);
        $expired = $this->storedCode(['code' => 'LATO15', 'startsAt' => '-60 days', 'endsAt' => '-30 days']);

        foreach (['disabled' => $disabled, 'planned' => $planned, 'active' => $active, 'expired' => $expired] as $status => $id) {
            $filtered = $this->send('GET', '/api/v1/admin/discount-codes?status='.$status);
            self::assertResponseStatusCodeSame(200);
            self::assertIsArray($filtered['items']);
            self::assertSame([$id], array_column($filtered['items'], 'id'), $status);
            self::assertSame([$status], array_column($filtered['items'], 'status'));
        }

        $prefixed = $this->send('GET', '/api/v1/admin/discount-codes?q='.rawurlencode(' wi '));
        self::assertIsArray($prefixed['items']);
        self::assertEqualsCanonicalizing([$planned, $active], array_column($prefixed['items'], 'id'));

        $both = $this->send('GET', '/api/v1/admin/discount-codes?status=planned&q=Wi');
        self::assertIsArray($both['items']);
        self::assertSame([$planned], array_column($both['items'], 'id'));

        $none = $this->send('GET', '/api/v1/admin/discount-codes?q='.rawurlencode('wi%'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'next' => null], $none);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function badListParameters(): iterable
    {
        $status = 'Status kodu musi być jednym z: disabled, planned, active, expired.';
        $q = 'Początek kodu musi mieć od 1 do 32 znaków.';
        $page = 'Liczba kodów na stronie musi być od 1 do 100.';
        $cursor = 'Kursor stronicowania jest nieprawidłowy.';

        yield 'an unknown status' => ['status=running', 'status', $status];
        yield 'an empty status' => ['status=', 'status', $status];
        yield 'an empty prefix' => ['q=', 'q', $q];
        yield 'a blank prefix' => ['q=%20%20', 'q', $q];
        yield 'a prefix of 33 characters' => ['q='.str_repeat('A', 33), 'q', $q];
        yield 'a prefix that is not UTF-8' => ['q=%FF', 'q', $q];
        yield 'a page of 0' => ['perPage=0', 'perPage', $page];
        yield 'a page of 101' => ['perPage=101', 'perPage', $page];
        yield 'a page that is no number' => ['perPage=many', 'perPage', $page];
        yield 'a fractional page' => ['perPage=2.5', 'perPage', $page];
        yield 'a negative page' => ['perPage=-1', 'perPage', $page];
        yield 'an empty page' => ['perPage=', 'perPage', $page];
        yield 'a page as a list' => ['perPage[]=2', 'perPage', $page];
        yield 'a status as a list' => ['status[]=active', 'status', $status];
        yield 'a prefix as a list' => ['q[]=WIT', 'q', $q];
        yield 'a cursor as a list' => ['after[]=x', 'after', $cursor];
        yield 'a cursor nobody handed out' => ['after=garbage', 'after', $cursor];
        yield 'an empty cursor' => ['after=', 'after', $cursor];
        yield 'a cursor that is not UTF-8' => ['after=%FF', 'after', $cursor];
        yield 'a cursor past the maximum length' => ['after='.str_repeat('a', 2049), 'after', $cursor];
    }

    #[DataProvider('badListParameters')]
    public function testBadListParametersAreABadRequest(string $query, string $field, ?string $title): void
    {
        $this->send('GET', '/api/v1/admin/discount-codes?'.$query);

        $problem = $this->assertProblem(400);
        self::assertIsArray($problem['violations']);
        self::assertSame([$field], array_column($problem['violations'], 'propertyPath'));
        if (null !== $title) {
            self::assertSame([$title], array_column($problem['violations'], 'title'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownCodes(): iterable
    {
        yield 'an unknown id' => ['/api/v1/admin/discount-codes/'.self::UNKNOWN];
        yield 'an id that is no UUID' => ['/api/v1/admin/discount-codes/code-1'];
    }

    #[DataProvider('unknownCodes')]
    public function testAnUnknownCodeIsNotFoundAlsoBeforeTheBody(string $path): void
    {
        $this->send('GET', $path);
        self::assertSame('Kod rabatowy nie istnieje.', $this->assertProblem(404)['detail']);
        $this->send('PUT', $path, $this->revision());
        $this->assertProblem(404);
        $this->send('PUT', $path, ['value' => 'many']);
        $this->assertProblem(404);
        $this->request('PUT', $path, '{"garbage"', TestTokenFactory::staff(['admin']));
        $this->assertProblem(404);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string, string|null}>
     */
    public static function invalidBodies(): iterable
    {
        $code = 'Kod ma 3–32 znaki: litery, cyfry i myślnik.';
        $percent = 'Rabat procentowy to 1–90%.';
        $amount = 'Kwota rabatu to 1–10 000 zł.';
        $minimumAboveAmount = 'Kod kwotowy wymaga minimum większego od kwoty rabatu.';
        $minimum = 'Minimum to 0,01–1 000 000 zł.';
        $end = 'Koniec musi być po początku.';
        $limit = 'Limit to 1–1 000 000 użyć.';
        $moment = 'Podaj czas w formacie RFC 3339 ze strefą, np. 2026-11-10T12:00:00Z.';
        $required = 'To pole jest wymagane.';

        yield 'no code' => [['code' => '__missing__'], 'code', $required];
        yield 'a null code' => [['code' => null], 'code', $code];
        yield 'a code of two characters' => [['code' => 'AB'], 'code', $code];
        yield 'a code of 33 characters' => [['code' => str_repeat('A', 33)], 'code', $code];
        yield 'a code with a Polish letter' => [['code' => 'ŻABA10'], 'code', $code];
        yield 'a code with a space inside' => [['code' => 'WIT AJ'], 'code', $code];
        yield 'a code with a NUL byte' => [['code' => "WIT\0AJ"], 'code', $code];
        yield 'a code of no-break spaces' => [['code' => "\u{A0}\u{A0}\u{A0}"], 'code', $code];
        yield 'a code with a line separator' => [['code' => "WIT\u{2028}AJ"], 'code', $code];
        yield 'a code that is no string' => [['code' => 12345], 'code', $code];
        yield 'no kind' => [['kind' => '__missing__'], 'kind', $required];
        yield 'an unknown kind' => [['kind' => 'free'], 'kind', 'Rodzaj rabatu to percent albo amount.'];
        yield 'a kind that is no string' => [['kind' => 1], 'kind', 'Rodzaj rabatu to percent albo amount.'];
        yield 'no value' => [['value' => '__missing__'], 'value', $required];
        yield 'a percent of 0' => [['value' => 0], 'value', $percent];
        yield 'a percent of 91' => [['value' => 91], 'value', $percent];
        yield 'a percent in a string' => [['value' => '10'], 'value', 'Wartość rabatu musi być liczbą całkowitą.'];
        yield 'a fractional percent' => [['value' => 10.5], 'value', 'Wartość rabatu musi być liczbą całkowitą.'];
        yield 'a percent of 10.0' => ['{"code": "WITAJ10", "kind": "percent", "value": 10.0, "minimumOrderGross": null, "startsAt": "2026-01-01T00:00:00Z", "endsAt": null, "usageLimit": null, "enabled": true}', 'value', null];
        yield 'an amount of 0,99 zł' => [['kind' => 'amount', 'value' => 99, 'minimumOrderGross' => 30000], 'value', $amount];
        yield 'an amount of 10 000,01 zł' => [['kind' => 'amount', 'value' => 1_000_001, 'minimumOrderGross' => 30_000_000], 'value', $amount];
        yield 'an amount without a minimum' => [['kind' => 'amount', 'value' => 5000], 'minimumOrderGross', $minimumAboveAmount];
        yield 'an amount with the minimum equal to it' => [['kind' => 'amount', 'value' => 5000, 'minimumOrderGross' => 5000], 'minimumOrderGross', $minimumAboveAmount];
        yield 'no minimum key' => [['minimumOrderGross' => '__missing__'], 'minimumOrderGross', $required];
        yield 'a minimum of 0' => [['minimumOrderGross' => 0], 'minimumOrderGross', $minimum];
        yield 'a minimum above a million zloty' => [['minimumOrderGross' => 100_000_001], 'minimumOrderGross', $minimum];
        yield 'a minimum in a string' => [['minimumOrderGross' => '100'], 'minimumOrderGross', $minimum];
        yield 'no start' => [['startsAt' => '__missing__'], 'startsAt', $required];
        yield 'a null start' => [['startsAt' => null], 'startsAt', $moment];
        yield 'a start without a time zone' => [['startsAt' => '2030-01-01T10:00:00'], 'startsAt', $moment];
        yield 'an impossible start' => [['startsAt' => '2030-02-31T10:00:00Z'], 'startsAt', $moment];
        yield 'a start that is a number' => [['startsAt' => 1893456000], 'startsAt', $moment];
        yield 'no end key' => [['endsAt' => '__missing__'], 'endsAt', $required];
        yield 'an end at the start' => [['startsAt' => '2030-01-01T10:00:00Z', 'endsAt' => '2030-01-01T10:00:00.900Z'], 'endsAt', $end];
        yield 'an end before the start' => [['startsAt' => '2030-01-02T10:00:00Z', 'endsAt' => '2030-01-01T10:00:00Z'], 'endsAt', $end];
        yield 'an end that is no moment' => [['endsAt' => 'tomorrow'], 'endsAt', $moment];
        yield 'no limit key' => [['usageLimit' => '__missing__'], 'usageLimit', $required];
        yield 'a limit of 0' => [['usageLimit' => 0], 'usageLimit', $limit];
        yield 'a limit of a million and one' => [['usageLimit' => 1_000_001], 'usageLimit', $limit];
        yield 'a limit in a string' => [['usageLimit' => '5'], 'usageLimit', $limit];
        yield 'no enabled' => [['enabled' => '__missing__'], 'enabled', $required];
        yield 'enabled in a string' => [['enabled' => 'true'], 'enabled', 'Włączenie kodu to true albo false.'];
        yield 'enabled null' => [['enabled' => null], 'enabled', 'Włączenie kodu to true albo false.'];
        yield 'a list body' => ['[1, 2]', '', null];
        yield 'an empty body' => ['', '', null];
    }

    /**
     * @param array<string, mixed>|string $change
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsUnprocessableWithAPolishMessageAndCreatesNothing(array|string $change, string $field, ?string $title): void
    {
        if (\is_string($change)) {
            $this->request('POST', '/api/v1/admin/discount-codes', $change, TestTokenFactory::staff(['marketing']));
        } else {
            $this->send('POST', '/api/v1/admin/discount-codes', self::changed($this->body(), $change));
        }

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        if ('' !== $field) {
            self::assertContains($field, array_column($problem['violations'], 'propertyPath'));
        }
        if (null !== $title) {
            self::assertContains(['propertyPath' => $field, 'title' => $title], $problem['violations'], json_encode($problem['violations'], \JSON_UNESCAPED_UNICODE) ?: '');
        }
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_discount_code'));
    }

    public function testABodyThatIsNotJsonIsUnsupportedAndJsonThatDoesNotParseIsABadRequest(): void
    {
        $id = $this->storedCode();
        $token = TestTokenFactory::staff(['admin']);

        foreach (['POST' => '/api/v1/admin/discount-codes', 'PUT' => '/api/v1/admin/discount-codes/'.$id] as $method => $path) {
            $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: (string) json_encode($this->revision()));
            $this->assertProblem(415);
            $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], content: (string) json_encode($this->revision()));
            $this->assertProblem(415);
            $this->request($method, $path, '{"garbage"', $token);
            $this->assertProblem(400);
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_discount_code WHERE id = ?', [$id]));
    }

    public function testKeysOfNoFieldAreIgnoredAndAJsonValueThatIsNoObjectHasNoKeys(): void
    {
        $this->send('POST', '/api/v1/admin/discount-codes', [...$this->body(), 'createdBy' => 'someone', 'id' => self::UNKNOWN]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['created_by_id' => TestTokenFactory::STAFF_ID], $this->connection->fetchAssociative('SELECT created_by_id FROM pricing_discount_code'));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM pricing_discount_code WHERE id = ?', [self::UNKNOWN]));

        $this->request('POST', '/api/v1/admin/discount-codes', '"WITAJ10"', TestTokenFactory::staff(['admin']));
        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertEqualsCanonicalizing(['code', 'kind', 'value', 'minimumOrderGross', 'usageLimit', 'enabled', 'startsAt', 'endsAt'], array_column($problem['violations'], 'propertyPath'));
        foreach (array_column($problem['violations'], 'title') as $title) {
            self::assertSame('To pole jest wymagane.', $title);
        }
    }

    public function testATextTakenInAnotherCaseIsAConflict(): void
    {
        $this->storedCode(['code' => 'WITAJ10']);

        $this->send('POST', '/api/v1/admin/discount-codes', [...$this->body(), 'code' => 'witaj10']);

        $problem = $this->assertProblem(409);
        self::assertSame(self::TYPE_TAKEN, $problem['type']);
        self::assertSame('Taki kod już istnieje.', $problem['detail']);
        $this->assertKeysWithinSchema($problem, 'PricingConflictProblem');
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM pricing_discount_code'));
    }

    public function testAPlannedCodeChangesEverythingButItsText(): void
    {
        $id = $this->storedCode(['code' => 'WIOSNA', 'startsAt' => '+1 day']);

        [$startsAt, $endsAt] = [$this->at('+2 days'), $this->at('+10 days')];
        $code = $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, [
            'kind' => 'amount', 'value' => 2000, 'minimumOrderGross' => 10000, 'startsAt' => $startsAt, 'endsAt' => $endsAt,
            'usageLimit' => 5, 'enabled' => false, 'version' => 1, 'code' => 'IGNORED',
        ], TestTokenFactory::staff(['admin'], ['preferred_username' => 'bartek']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['id' => $id, 'code' => 'WIOSNA', 'kind' => 'amount', 'value' => 2000, 'minimumOrderGross' => 10000, 'startsAt' => $startsAt, 'endsAt' => $endsAt, 'usageLimit' => 5, 'activeUses' => 0, 'enabled' => false, 'status' => 'disabled', 'version' => 2],
            $code,
        );
        $this->assertNotStored();
        self::assertSame(['updated_by_id' => TestTokenFactory::STAFF_ID, 'updated_by_name' => 'bartek'], $this->connection->fetchAssociative('SELECT updated_by_id, updated_by_name FROM pricing_discount_code WHERE id = ?', [$id]));
    }

    public function testAnActiveCodeChangesItsEndEnabledAndLimit(): void
    {
        $id = $this->storedCode(['usageLimit' => 10]);
        $this->use($id, 2);

        $endsAt = $this->at('+5 days');
        $code = $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, $this->revision(['endsAt' => $endsAt, 'usageLimit' => 2, 'enabled' => false]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$endsAt, 2, 2, false, 2], [$code['endsAt'], $code['usageLimit'], $code['activeUses'], $code['enabled'], $code['version']]);
    }

    public function testAnActiveCodeLosesItsLimit(): void
    {
        $id = $this->storedCode(['usageLimit' => 10]);

        $code = $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, $this->revision(['usageLimit' => null]));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($code['usageLimit']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int|null}>
     */
    public static function changesAfterTheStart(): iterable
    {
        yield 'another percent' => [['value' => 20], 10];
        yield 'another kind' => [['kind' => 'amount', 'value' => 1000, 'minimumOrderGross' => 5000], 10];
        yield 'a minimum' => [['minimumOrderGross' => 5000], 10];
        yield 'another start' => [['startsAt' => '-2 days'], 10];
        yield 'a limit added to a code without one' => [['usageLimit' => 100], null];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('changesAfterTheStart')]
    public function testAnActiveCodeRefusesAnyOtherChange(array $change, ?int $limit): void
    {
        $id = $this->storedCode(['usageLimit' => $limit]);
        if (\is_string($change['startsAt'] ?? null)) {
            $change['startsAt'] = $this->at($change['startsAt']);
        }

        $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, $this->revision(['usageLimit' => $limit, ...$change]));

        $problem = $this->assertProblem(409);
        self::assertSame(self::TYPE_NOT_EDITABLE, $problem['type']);
        self::assertSame('Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty.', $problem['detail']);
        $this->assertKeysWithinSchema($problem, 'PricingConflictProblem');
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_discount_code WHERE id = ?', [$id]));
    }

    public function testAReviseOfAnotherVersionIsAConflict(): void
    {
        $id = $this->storedCode();

        $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, $this->revision(['enabled' => false, 'version' => 2]));

        $problem = $this->assertProblem(409);
        self::assertSame('about:blank', $problem['type']);
        self::assertSame('Kod rabatowy został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.', $problem['detail']);
        self::assertSame([1, true], [$this->connection->fetchOne('SELECT version FROM pricing_discount_code WHERE id = ?', [$id]), $this->connection->fetchOne('SELECT enabled FROM pricing_discount_code WHERE id = ?', [$id])]);
    }

    public function testALimitBelowTheActiveUsesIsUnprocessableWithTheirNumber(): void
    {
        $id = $this->storedCode(['usageLimit' => 10]);
        $this->use($id, 3);

        $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, $this->revision(['usageLimit' => 2]));

        $problem = $this->assertProblem(422);
        self::assertSame([['propertyPath' => 'usageLimit', 'title' => 'Limit nie może być mniejszy od liczby użyć (3).']], $problem['violations']);
    }

    public function testAChangedEndNotLaterThanNowIsUnprocessable(): void
    {
        $id = $this->storedCode();

        $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, $this->revision(['endsAt' => $this->at('now')]));

        $problem = $this->assertProblem(422);
        self::assertSame([['propertyPath' => 'endsAt', 'title' => 'Koniec musi być w przyszłości.']], $problem['violations']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidRevisions(): iterable
    {
        yield 'no version' => [['version' => '__missing__'], 'version', 'To pole jest wymagane.'];
        yield 'a version in a string' => [['version' => '1'], 'version', 'Wersja musi być dodatnią liczbą całkowitą.'];
        yield 'a version of 0' => [['version' => 0], 'version', 'Wersja musi być dodatnią liczbą całkowitą.'];
        yield 'a percent of 0' => [['value' => 0], 'value', 'Rabat procentowy to 1–90%.'];
        yield 'an end before the start' => [['endsAt' => '2000-01-01T00:00:00Z'], 'endsAt', 'Koniec musi być po początku.'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('invalidRevisions')]
    public function testAnInvalidRevisionIsUnprocessable(array $change, string $field, string $title): void
    {
        $id = $this->storedCode();

        $this->send('PUT', '/api/v1/admin/discount-codes/'.$id, self::changed($this->revision(), $change));

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertContains(['propertyPath' => $field, 'title' => $title], $problem['violations']);
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM pricing_discount_code WHERE id = ?', [$id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return ['code' => 'WITAJ10', 'kind' => 'percent', 'value' => 10, 'minimumOrderGross' => null, 'startsAt' => $this->at('-1 day'), 'endsAt' => null, 'usageLimit' => null, 'enabled' => true];
    }

    /**
     * The body of a revise of a code stored by storedCode() with the defaults (an enabled 10 % code started at STARTED).
     *
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function revision(array $change = []): array
    {
        return [...['kind' => 'percent', 'value' => 10, 'minimumOrderGross' => null, 'startsAt' => self::STARTED, 'endsAt' => null, 'usageLimit' => null, 'enabled' => true, 'version' => 1], ...$change];
    }

    /**
     * The body with the change; `__missing__` drops the key.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private static function changed(array $body, array $change): array
    {
        return array_filter([...$body, ...$change], static fn (mixed $value): bool => '__missing__' !== $value);
    }

    /**
     * The whole second of a moment relative to now, in UTC (RFC 3339).
     */
    private function at(string $relative): string
    {
        return new \DateTimeImmutable('@'.new \DateTimeImmutable($relative)->getTimestamp())->format(\DATE_ATOM);
    }

    /**
     * A code stored as the factory makes it (by default an enabled 10 % code started at STARTED, created now); the
     * moments of the attributes are relative to now and cut to their second like at().
     *
     * @param array<string, mixed> $attributes
     */
    private function storedCode(array $attributes = []): string
    {
        foreach (['startsAt', 'endsAt', 'createdAt'] as $moment) {
            if (\is_string($attributes[$moment] ?? null)) {
                $attributes[$moment] = $this->at($attributes[$moment]);
            }
        }
        $attributes['startsAt'] ??= self::STARTED;
        $code = DiscountCodeFactory::createOne($attributes);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);
        $this->entityManager->clear();

        return $code->id()->toString();
    }

    private function use(string $codeId, int $times): void
    {
        $usages = new DoctrineDiscountCodeUsageRepository($this->entityManager);
        for ($i = 0; $i < $times; ++$i) {
            $usages->add(DiscountCodeUsage::record(DiscountCodeUsageId::fromString(Uuid::v7()->toRfc4122()), DiscountCodeId::fromString($codeId), OrderId::fromString(Uuid::v7()->toRfc4122()), new \DateTimeImmutable()));
        }
        $this->entityManager->clear();
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
