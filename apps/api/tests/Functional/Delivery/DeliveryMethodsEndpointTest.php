<?php

declare(strict_types=1);

namespace App\Tests\Functional\Delivery;

use App\Tests\Support\InputConformance\InputValues;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The delivery methods of the panel (/api/v1/admin/delivery-methods, docs/features/delivery/spec.md, W1) on PostgreSQL
 * (shop_test, rolled back after each test).
 */
final class DeliveryMethodsEndpointTest extends WebTestCase
{
    private const string PATH = '/api/v1/admin/delivery-methods';
    private const string TYPE_NAME_TAKEN = 'tag:piotrp321.github.io,2026:shop/problems/delivery-method-name-taken';
    private const string TYPE_LIMIT_REACHED = 'tag:piotrp321.github.io,2026:shop/problems/delivery-method-limit-reached';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8000';
    private const string REQUIRED = 'To pole jest wymagane.';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        // Only the methods of the test are in the list.
        $this->connection->executeStatement('DELETE FROM delivery_method');
    }

    public function testAnAdministratorCreatesAMethod(): void
    {
        $method = $this->send('POST', self::PATH, $this->body(['name' => "  Kurier standardowy\u{A0}", 'description' => ' Dostawa w 2–3 dni robocze ']));

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($method['id']);
        self::assertTrue(Uuid::isValid($method['id']));
        self::assertSame(self::PATH.'/'.$method['id'], $this->client->getResponse()->headers->get('Location'));
        self::assertSame(
            ['id' => $method['id'], 'name' => 'Kurier standardowy', 'description' => 'Dostawa w 2–3 dni robocze', 'priceGross' => 1999, 'freeFromGross' => 29_900, 'active' => true, 'position' => 10, 'trackingUrlTemplate' => 'https://tracking.example.com/{numer}', 'version' => 1],
            $method,
        );
        $this->assertKeysMatchSchema($method, 'DeliveryMethodResponse');
        $this->assertNotStored();
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
    }

    public function testAMethodWithoutTheOptionalValuesIsCreated(): void
    {
        $method = $this->send('POST', self::PATH, $this->body(['description' => null, 'priceGross' => 0, 'freeFromGross' => null, 'active' => false, 'position' => 0, 'trackingUrlTemplate' => null]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['', 0, null, false, 0, null], [$method['description'], $method['priceGross'], $method['freeFromGross'], $method['active'], $method['position'], $method['trackingUrlTemplate']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankUnicodeTexts(): iterable
    {
        yield from InputValues::blankUnicodeTexts();
    }

    #[DataProvider('blankUnicodeTexts')]
    public function testABlankDescriptionIsNoneAndABlankTemplateIsNone(string $blank): void
    {
        $method = $this->send('POST', self::PATH, $this->body(['description' => $blank, 'trackingUrlTemplate' => $blank]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['', null], [$method['description'], $method['trackingUrlTemplate']]);
        self::assertSame(['description' => '', 'tracking_url_template' => null], $this->connection->fetchAssociative('SELECT description, tracking_url_template FROM delivery_method'));
    }

    #[DataProvider('blankUnicodeTexts')]
    public function testABlankNameIsRequired(string $blank): void
    {
        $this->send('POST', self::PATH, $this->body(['name' => $blank]));

        $this->assertViolation('name', 'Nazwa jest wymagana.');
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
    }

    public function testTheListHasTheActiveMethodsByPositionAndNameThenTheInactiveOnesByName(): void
    {
        $zeta = $this->stored('Zeta', active: false, position: 0);
        $courierB = $this->stored('Kurier B', position: 20);
        $alfa = $this->stored('Alfa', active: false, position: 999);
        $courierA = $this->stored('Kurier A', position: 20);
        $parcel = $this->stored('Paczka', position: 5);

        $list = $this->send('GET', self::PATH);

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($list['items']);
        self::assertSame([$parcel, $courierA, $courierB, $alfa, $zeta], array_column($list['items'], 'id'));
        $this->assertKeysMatchSchema($list, 'DeliveryMethodListResponse');
        self::assertIsArray($list['items'][0]);
        $this->assertKeysMatchSchema($list['items'][0], 'DeliveryMethodResponse');
        $this->assertNotStored();
    }

    public function testAnEmptyListHasNoItems(): void
    {
        self::assertSame(['items' => []], $this->send('GET', self::PATH));
        self::assertResponseStatusCodeSame(200);
    }

    public function testAMethodIsReadAlsoByAnotherNotationOfItsId(): void
    {
        $id = $this->stored('Kurier', price: 2500);

        $method = $this->send('GET', self::PATH.'/'.$id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$id, 'Kurier', 2500, 1], [$method['id'], $method['name'], $method['priceGross'], $method['version']]);
        $this->assertKeysMatchSchema($method, 'DeliveryMethodResponse');
        $this->assertNotStored();

        foreach (InputValues::equivalentUuidForms($id) as [$notation]) {
            self::assertSame($method, $this->send('GET', self::PATH.'/'.$notation));
            self::assertResponseStatusCodeSame(200);
        }
    }

    public function testAnAdministratorChangesAMethodAtItsVersion(): void
    {
        $id = $this->stored('Kurier');

        $method = $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => 'Kurier ekspresowy', 'priceGross' => 3999, 'freeFromGross' => null, 'active' => false, 'position' => 20, 'trackingUrlTemplate' => null, 'version' => 1]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['id' => $id, 'name' => 'Kurier ekspresowy', 'description' => 'Dostawa w 2–3 dni robocze', 'priceGross' => 3999, 'freeFromGross' => null, 'active' => false, 'position' => 20, 'trackingUrlTemplate' => null, 'version' => 2],
            $method,
        );
        $this->assertNotStored();
        self::assertSame(['name' => 'Kurier ekspresowy', 'version' => 2], $this->connection->fetchAssociative('SELECT name, version FROM delivery_method WHERE id = ?', [$id]));
    }

    /**
     * Texts stored before the rule of texts that the rule refuses (docs/features/api-input-contract/spec.md, E7).
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function storedTextsOutsideTheRule(): iterable
    {
        yield 'U+200B as the whole name' => ["\u{200B}", 'Dostawa w 2–3 dni robocze', 'Nazwa jest wymagana.'];
        yield 'U+009F in the name' => ["Kurier\u{9F}A", 'Dostawa w 2–3 dni robocze', 'Nazwa zawiera niedozwolone znaki.'];
        yield 'a tab in the name and the description' => ["Kurier\tA", "Dostawa\tjutro", 'Nazwa zawiera niedozwolone znaki.'];
    }

    /**
     * The read of a stored method does not check its texts again: the list and the method show them as stored, and only
     * a change, which sends every field, has to fix the name.
     */
    #[DataProvider('storedTextsOutsideTheRule')]
    public function testAMethodStoredWithATextOutsideTheRuleIsReadUnchangedAndItsChangeHasToFixTheName(string $name, string $description, string $title): void
    {
        $id = $this->stored($name, description: $description);

        $list = $this->send('GET', self::PATH);
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($list['items']);
        $items = [];
        foreach ($list['items'] as $item) {
            self::assertIsArray($item);
            $items[] = [$item['id'], $item['name'], $item['description']];
        }
        self::assertSame([[$id, $name, $description]], $items);

        $method = $this->send('GET', self::PATH.'/'.$id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$id, $name, $description], [$method['id'], $method['name'], $method['description']]);

        $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => $name, 'description' => 'Jutro', 'version' => 1]));
        $this->assertViolation('name', $title);
        self::assertSame(['name' => $name, 'description' => $description, 'version' => 1], $this->connection->fetchAssociative('SELECT name, description, version FROM delivery_method WHERE id = ?', [$id]));

        $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => 'Kurier poprawiony', 'description' => 'Jutro', 'version' => 1]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['name' => 'Kurier poprawiony', 'description' => 'Jutro', 'version' => 2], $this->connection->fetchAssociative('SELECT name, description, version FROM delivery_method WHERE id = ?', [$id]));
    }

    public function testAChangeKeepingItsOwnNameInAnotherCaseIsAllowed(): void
    {
        $id = $this->stored('kurier');

        $method = $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => 'KURIER', 'version' => 1]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('KURIER', $method['name']);
    }

    public function testAChangeOfAnotherVersionIsAConflictAndChangesNothing(): void
    {
        $id = $this->stored('Kurier', version: 2);

        $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => 'Inny', 'version' => 1]));

        $problem = $this->assertProblem(409);
        self::assertSame(['about:blank', 'Metoda dostawy została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.'], [$problem['type'], $problem['detail']]);
        $this->assertKeysWithinSchema($problem, 'DeliveryConflictProblem');
        self::assertSame(['name' => 'Kurier', 'version' => 2], $this->connection->fetchAssociative('SELECT name, version FROM delivery_method WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function namesTaken(): iterable
    {
        yield 'the same name' => ['Kurier', 'Kurier', true];
        yield 'upper case' => ['kurier', 'KURIER', true];
        yield 'a Polish capital letter' => ['łódź kurier', 'ŁÓDŹ KURIER', true];
        yield 'a Polish small letter' => ['Łódź', 'łódź', true];
        yield 'the name of an inactive method' => ['Kurier', 'kurier', false];
        yield 'trimmed to the name' => ['Kurier', "  kurier\u{A0}", true];
    }

    #[DataProvider('namesTaken')]
    public function testANameTakenWithoutRegardToCaseIsAConflictOnCreate(string $stored, string $name, bool $active): void
    {
        $this->stored($stored, active: $active);

        $this->send('POST', self::PATH, $this->body(['name' => $name]));

        $problem = $this->assertProblem(409);
        self::assertSame([self::TYPE_NAME_TAKEN, 'Metoda dostawy o tej nazwie już istnieje.'], [$problem['type'], $problem['detail']]);
        $this->assertKeysWithinSchema($problem, 'DeliveryConflictProblem');
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
    }

    #[DataProvider('namesTaken')]
    public function testANameTakenWithoutRegardToCaseIsAConflictOnChange(string $stored, string $name, bool $active): void
    {
        $this->stored($stored, active: $active);
        $id = $this->stored('Inna metoda');

        $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => $name, 'version' => 1]));

        self::assertSame(self::TYPE_NAME_TAKEN, $this->assertProblem(409)['type']);
        self::assertSame(['name' => 'Inna metoda', 'version' => 1], $this->connection->fetchAssociative('SELECT name, version FROM delivery_method WHERE id = ?', [$id]));
    }

    public function testATwentyFirstActiveMethodIsAConflictOnCreate(): void
    {
        $this->storeActive(20);

        $this->send('POST', self::PATH, $this->body());

        $problem = $this->assertProblem(409);
        self::assertSame([self::TYPE_LIMIT_REACHED, 'Aktywnych metod dostawy może być najwyżej 20. Wyłącz inną metodę.'], [$problem['type'], $problem['detail']]);
        $this->assertKeysWithinSchema($problem, 'DeliveryConflictProblem');
        self::assertSame(20, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
    }

    public function testATwentiethActiveMethodAndAnInactiveOneAreCreated(): void
    {
        $this->storeActive(19);

        $this->send('POST', self::PATH, $this->body(['name' => 'Dwudziesta']));
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', self::PATH, $this->body(['name' => 'Nieaktywna', 'active' => false]));
        self::assertResponseStatusCodeSame(201);

        self::assertSame(20, $this->connection->fetchOne('SELECT count(*) FROM delivery_method WHERE active'));
    }

    public function testActivatingATwentyFirstMethodIsAConflict(): void
    {
        $this->storeActive(20);
        $id = $this->stored('Wyłączona', active: false);

        $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => 'Wyłączona', 'active' => true, 'version' => 1]));

        self::assertSame(self::TYPE_LIMIT_REACHED, $this->assertProblem(409)['type']);
        self::assertSame(['active' => false, 'version' => 1], $this->connection->fetchAssociative('SELECT active, version FROM delivery_method WHERE id = ?', [$id]));
    }

    public function testChangingAnInactiveMethodWithoutActivatingItPassesAtTheLimit(): void
    {
        $this->storeActive(20);
        $id = $this->stored('Wyłączona', active: false);

        $method = $this->send('PUT', self::PATH.'/'.$id, $this->body(['name' => 'Wyłączona', 'priceGross' => 999, 'active' => false, 'version' => 1]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([999, false, 2], [$method['priceGross'], $method['active'], $method['version']]);
    }

    public function testChangingAnActiveMethodPassesAtTheLimit(): void
    {
        $ids = $this->storeActive(20);

        $this->send('PUT', self::PATH.'/'.$ids[0], $this->body(['name' => 'Aktywna 1', 'priceGross' => 999, 'version' => 1]));

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function otherStaff(): iterable
    {
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'marketing' => [['marketing']];
        yield 'every role but admin' => [['support', 'warehouse', 'marketing']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('otherStaff')]
    public function testOtherStaffMayNotReadOrChangeMethodsAndAreAskedBeforeTheBody(array $roles): void
    {
        $id = $this->stored('Kurier');
        $token = TestTokenFactory::staff($roles);

        foreach ([['GET', self::PATH, null], ['GET', self::PATH.'/'.$id, null], ['POST', self::PATH, '{"garbage"'], ['PUT', self::PATH.'/'.$id, '{"garbage"'], ['PUT', self::PATH.'/'.self::UNKNOWN, '{"garbage"'], ['GET', self::PATH.'/'.self::UNKNOWN, null]] as [$method, $path, $body]) {
            $this->request($method, $path, $body, $token);
            $this->assertProblem(403);
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM delivery_method WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function everyEndpoint(): iterable
    {
        yield 'list' => ['GET', self::PATH, null];
        yield 'create' => ['POST', self::PATH, '{}'];
        yield 'read' => ['GET', self::PATH.'/'.self::UNKNOWN, null];
        yield 'change' => ['PUT', self::PATH.'/'.self::UNKNOWN, '{}'];
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownMethods(): iterable
    {
        yield 'an unknown id' => [self::PATH.'/'.self::UNKNOWN];
        yield 'an id that is no UUID' => [self::PATH.'/kurier-1'];
        yield 'a UUID with a trailing character' => [self::PATH.'/'.self::UNKNOWN.'0'];
        foreach (InputValues::uuidsOutsideTheVersions() as $name => [$uuid]) {
            yield $name => [self::PATH.'/'.$uuid];
        }
    }

    #[DataProvider('unknownMethods')]
    public function testAnUnknownMethodIsNotFoundAlsoBeforeTheBody(string $path): void
    {
        $this->stored('Kurier');

        $this->send('GET', $path);
        self::assertSame('Metoda dostawy nie istnieje.', $this->assertProblem(404)['detail']);
        $this->send('PUT', $path, $this->body(['version' => 1]));
        self::assertSame('Metoda dostawy nie istnieje.', $this->assertProblem(404)['detail']);
        $this->send('PUT', $path, ['priceGross' => 'many']);
        $this->assertProblem(404);
        $this->request('PUT', $path, '{"garbage"', TestTokenFactory::staff(['admin']));
        $this->assertProblem(404);
        $this->client->request('PUT', $path, server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])], content: 'x');
        $this->assertProblem(404);
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string, string}>
     */
    public static function invalidBodies(): iterable
    {
        $name = 'Nazwa jest wymagana.';
        $description = 'Opis musi być tekstem.';
        $price = 'Cena musi być liczbą całkowitą groszy.';
        $priceRange = 'Cena musi wynosić od 0,00 do 999 999,99 zł.';
        $freeFrom = 'Próg darmowej dostawy musi być liczbą całkowitą groszy.';
        $freeFromRange = 'Próg darmowej dostawy musi wynosić od 0,01 do 999 999 999,99 zł.';
        $active = 'Wskaż, czy metoda jest aktywna.';
        $position = 'Kolejność musi być liczbą całkowitą od 0 do 999.';
        $template = 'Link śledzenia musi być tekstem.';
        $address = 'Link śledzenia musi zaczynać się od https:// i mieć nazwę hosta.';
        $characters = 'Link śledzenia nie może zawierać spacji ani znaków sterujących.';
        $number = 'Link śledzenia musi zawierać dokładnie jedno {numer}.';
        $prefix = 'https://example.com/';

        yield 'no name' => [['name' => '__missing__'], 'name', self::REQUIRED];
        yield 'a null name' => [['name' => null], 'name', $name];
        yield 'a name that is no string' => [['name' => 123], 'name', $name];
        yield 'a name that is a list' => [['name' => ['Kurier']], 'name', $name];
        yield 'an empty name' => [['name' => ''], 'name', $name];
        yield 'a name of 101 characters' => [['name' => str_repeat('ł', 101)], 'name', 'Nazwa może mieć najwyżej 100 znaków.'];
        yield 'a name with a NUL byte' => [['name' => "Kur\0ier"], 'name', 'Nazwa zawiera niedozwolone znaki.'];
        yield 'a name with a line separator' => [['name' => "Kur\u{2028}ier"], 'name', 'Nazwa zawiera niedozwolone znaki.'];
        yield 'a name with a line feed' => [['name' => "Kurier\n"], 'name', 'Nazwa zawiera niedozwolone znaki.'];
        yield 'no description' => [['description' => '__missing__'], 'description', self::REQUIRED];
        yield 'a description that is no string' => [['description' => 12], 'description', $description];
        yield 'a description that is a boolean' => [['description' => false], 'description', $description];
        yield 'a description of 201 characters' => [['description' => str_repeat('a', 201)], 'description', 'Opis może mieć najwyżej 200 znaków.'];
        yield 'a description of two lines' => [['description' => "Dostawa\nw 2 dni"], 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'a description with a NUL byte' => [['description' => "Dostawa\0"], 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'a description with a line separator' => [['description' => "Dostawa\u{2028}"], 'description', 'Opis zawiera niedozwolone znaki.'];
        yield 'no price' => [['priceGross' => '__missing__'], 'priceGross', self::REQUIRED];
        yield 'a null price' => [['priceGross' => null], 'priceGross', 'Cena jest wymagana.'];
        yield 'a price in a string' => [['priceGross' => '1999'], 'priceGross', $price];
        yield 'a fractional price' => [['priceGross' => 19.99], 'priceGross', $price];
        // json_encode() writes 1999.0 as 1999, so the body is written by hand.
        yield 'a price as a whole float' => ['{"name": "Kurier", "description": null, "priceGross": 1999.0, "freeFromGross": null, "active": true, "position": 10, "trackingUrlTemplate": null, "version": 1}', 'priceGross', $price];
        yield 'a price that is a boolean' => [['priceGross' => true], 'priceGross', $price];
        yield 'a negative price' => [['priceGross' => -1], 'priceGross', $priceRange];
        yield 'a price of a million zloty' => [['priceGross' => 100_000_000], 'priceGross', $priceRange];
        yield 'no threshold key' => [['freeFromGross' => '__missing__'], 'freeFromGross', self::REQUIRED];
        yield 'a threshold in a string' => [['freeFromGross' => '29900'], 'freeFromGross', $freeFrom];
        yield 'a fractional threshold' => [['freeFromGross' => 299.5], 'freeFromGross', $freeFrom];
        yield 'a threshold of 0' => [['freeFromGross' => 0], 'freeFromGross', $freeFromRange];
        yield 'a negative threshold' => [['freeFromGross' => -1], 'freeFromGross', $freeFromRange];
        yield 'a threshold of a billion zloty' => [['freeFromGross' => 100_000_000_000], 'freeFromGross', $freeFromRange];
        yield 'no active' => [['active' => '__missing__'], 'active', self::REQUIRED];
        yield 'a null active' => [['active' => null], 'active', $active];
        yield 'active in a string' => [['active' => 'true'], 'active', $active];
        yield 'active as a number' => [['active' => 1], 'active', $active];
        yield 'no position' => [['position' => '__missing__'], 'position', self::REQUIRED];
        yield 'a null position' => [['position' => null], 'position', $position];
        yield 'a position in a string' => [['position' => '10'], 'position', $position];
        yield 'a fractional position' => [['position' => 1.5], 'position', $position];
        yield 'a negative position' => [['position' => -1], 'position', $position];
        yield 'a position of 1000' => [['position' => 1000], 'position', $position];
        yield 'no template key' => [['trackingUrlTemplate' => '__missing__'], 'trackingUrlTemplate', self::REQUIRED];
        yield 'a template that is no string' => [['trackingUrlTemplate' => 5], 'trackingUrlTemplate', $template];
        yield 'a template that is an object' => [['trackingUrlTemplate' => ['url' => 'https://example.com/{numer}']], 'trackingUrlTemplate', $template];
        yield 'a template of 501 characters' => [['trackingUrlTemplate' => $prefix.str_repeat('a', 501 - \strlen($prefix) - 7).'{numer}'], 'trackingUrlTemplate', 'Link śledzenia może mieć najwyżej 500 znaków.'];
        yield 'an http template' => [['trackingUrlTemplate' => 'http://example.com/{numer}'], 'trackingUrlTemplate', $address];
        yield 'a template without a host' => [['trackingUrlTemplate' => 'https:///{numer}'], 'trackingUrlTemplate', $address];
        yield 'a template without a scheme' => [['trackingUrlTemplate' => 'example.com/{numer}'], 'trackingUrlTemplate', $address];
        yield 'a template with a space' => [['trackingUrlTemplate' => 'https://example.com/a b/{numer}'], 'trackingUrlTemplate', $characters];
        yield 'a template with a NUL byte' => [['trackingUrlTemplate' => "https://example.com/\0{numer}"], 'trackingUrlTemplate', $characters];
        yield 'a template with a line separator' => [['trackingUrlTemplate' => "https://example.com/\u{2028}{numer}"], 'trackingUrlTemplate', $characters];
        yield 'a template without the number' => [['trackingUrlTemplate' => 'https://example.com/track'], 'trackingUrlTemplate', $number];
        yield 'a template with two numbers' => [['trackingUrlTemplate' => 'https://example.com/{numer}?n={numer}'], 'trackingUrlTemplate', $number];
        yield 'a list body' => ['[1, 2]', 'name', self::REQUIRED];
        yield 'an empty body' => ['', 'priceGross', self::REQUIRED];
        yield 'a JSON string' => ['"Kurier"', 'active', self::REQUIRED];
    }

    /**
     * @param array<string, mixed>|string $change
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsUnprocessableWithAPolishMessageAndCreatesNothing(array|string $change, string $field, string $title): void
    {
        if (\is_string($change)) {
            $this->request('POST', self::PATH, $change, TestTokenFactory::staff(['admin']));
        } else {
            $this->send('POST', self::PATH, $this->body($change));
        }

        $this->assertViolation($field, $title);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM delivery_method'));
    }

    /**
     * @param array<string, mixed>|string $change
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyOfAChangeIsUnprocessableAndChangesNothing(array|string $change, string $field, string $title): void
    {
        $id = $this->stored('Kurier');
        if (\is_string($change)) {
            $this->request('PUT', self::PATH.'/'.$id, $change, TestTokenFactory::staff(['admin']));
        } else {
            $this->send('PUT', self::PATH.'/'.$id, $this->body([...$change, 'version' => 1]));
        }

        $this->assertViolation($field, $title);
        self::assertSame(['name' => 'Kurier', 'version' => 1], $this->connection->fetchAssociative('SELECT name, version FROM delivery_method WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'no version' => ['__missing__', self::REQUIRED];
        yield 'a null version' => [null, 'Wersja metody jest wymagana.'];
        yield 'a version of 0' => [0, 'Wersja metody musi być liczbą całkowitą od 1.'];
        yield 'a negative version' => [-1, 'Wersja metody musi być liczbą całkowitą od 1.'];
        yield 'a version in a string' => ['1', 'Wersja metody musi być liczbą całkowitą od 1.'];
        yield 'a fractional version' => [1.5, 'Wersja metody musi być liczbą całkowitą od 1.'];
    }

    #[DataProvider('invalidVersions')]
    public function testAnInvalidVersionIsUnprocessable(mixed $version, string $title): void
    {
        $id = $this->stored('Kurier');

        $this->send('PUT', self::PATH.'/'.$id, $this->body(['version' => $version]));

        $this->assertViolation('version', $title);
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM delivery_method WHERE id = ?', [$id]));
    }

    public function testEveryKeyOfAnEmptyObjectIsRequiredAtItsField(): void
    {
        $id = $this->stored('Kurier');

        foreach (['POST' => [self::PATH, []], 'PUT' => [self::PATH.'/'.$id, ['version']]] as $method => [$path, $extra]) {
            $this->request($method, $path, '{}', TestTokenFactory::staff(['admin']));
            $problem = $this->assertProblem(422);
            self::assertIsArray($problem['violations']);
            self::assertEqualsCanonicalizing(
                ['name', 'description', 'priceGross', 'freeFromGross', 'active', 'position', 'trackingUrlTemplate', ...$extra],
                array_column($problem['violations'], 'propertyPath'),
            );
            foreach (array_column($problem['violations'], 'title') as $title) {
                self::assertSame(self::REQUIRED, $title);
            }
        }
    }

    public function testEveryInvalidFieldIsReportedOnceAtItsField(): void
    {
        $this->send('POST', self::PATH, ['name' => null, 'description' => 1, 'priceGross' => -1, 'freeFromGross' => 0, 'active' => 'yes', 'position' => 1000, 'trackingUrlTemplate' => 'http://x/{numer}']);

        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        self::assertSame(['name', 'description', 'priceGross', 'freeFromGross', 'active', 'position', 'trackingUrlTemplate'], array_column($problem['violations'], 'propertyPath'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUtf8(): iterable
    {
        foreach (['name', 'description', 'trackingUrlTemplate'] as $field) {
            yield "$field with a malformed byte" => [$field, "a\xC3\x28b"];
            yield "$field with a lone low surrogate" => [$field, 'a\udc80b'];
            yield "$field with a lone high surrogate" => [$field, 'a\ud800b'];
        }
    }

    /**
     * Invalid UTF-8 never reaches the validator of a write: the JSON decoder refuses a body with a malformed byte or a
     * lone surrogate escape first, so the answer is 400 and nothing is saved. The rule for such a text in the value
     * objects is covered by DeliveryMethodValuesTest.
     */
    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8CannotReachAFieldAndIsABadRequest(string $field, string $text): void
    {
        $id = $this->stored('Kurier');
        $placeholder = '__TEXT__';
        $token = TestTokenFactory::staff(['admin']);

        $this->request('POST', self::PATH, str_replace('"'.$placeholder.'"', '"'.$text.'"', json_encode($this->body([$field => $placeholder]), \JSON_THROW_ON_ERROR)), $token);
        $this->assertProblem(400);
        $this->request('PUT', self::PATH.'/'.$id, str_replace('"'.$placeholder.'"', '"'.$text.'"', json_encode($this->body([$field => $placeholder, 'version' => 1]), \JSON_THROW_ON_ERROR)), $token);
        $this->assertProblem(400);

        self::assertSame(['count' => 1, 'version' => 1], $this->connection->fetchAssociative('SELECT count(*) AS count, max(version) AS version FROM delivery_method'));
    }

    public function testABodyThatIsNotJsonIsUnsupportedAndJsonThatDoesNotParseIsABadRequest(): void
    {
        $id = $this->stored('Kurier');
        $token = TestTokenFactory::staff(['admin']);

        foreach (['POST' => self::PATH, 'PUT' => self::PATH.'/'.$id] as $method => $path) {
            $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: (string) json_encode($this->body(['version' => 1])));
            $this->assertProblem(415);
            $this->request($method, $path, '{"garbage"', $token);
            $this->assertProblem(400);
        }
        self::assertSame(['count' => 1, 'version' => 1], $this->connection->fetchAssociative('SELECT count(*) AS count, max(version) AS version FROM delivery_method'));
    }

    public function testKeysOfNoFieldAreIgnored(): void
    {
        $method = $this->send('POST', self::PATH, [...$this->body(), 'id' => self::UNKNOWN, 'version' => 7]);

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame(self::UNKNOWN, $method['id']);
        self::assertSame(1, $method['version']);
    }

    /**
     * @param array<string, mixed> $change `__missing__` drops the key
     *
     * @return array<string, mixed>
     */
    private function body(array $change = []): array
    {
        $body = [...[
            'name' => 'Kurier standardowy',
            'description' => 'Dostawa w 2–3 dni robocze',
            'priceGross' => 1999,
            'freeFromGross' => 29_900,
            'active' => true,
            'position' => 10,
            'trackingUrlTemplate' => 'https://tracking.example.com/{numer}',
        ], ...$change];

        return array_filter($body, static fn (mixed $value): bool => '__missing__' !== $value);
    }

    /**
     * @return list<string> the ids of the methods, named „Aktywna 1” to „Aktywna N”
     */
    private function storeActive(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; ++$i) {
            $ids[] = $this->stored('Aktywna '.$i);
        }

        return $ids;
    }

    private function stored(string $name, bool $active = true, int $position = 10, int $price = 1999, int $version = 1, string $description = 'Dostawa w 2–3 dni robocze'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection->insert('delivery_method', [
            'id' => $id, 'name' => $name, 'description' => $description, 'price_gross' => $price, 'free_from_gross' => null,
            'active' => $active, 'position' => $position, 'tracking_url_template' => null,
            'created_at' => '2026-11-20 10:00:00+00', 'updated_at' => '2026-11-20 10:00:00+00', 'version' => $version,
        ], ['active' => ParameterType::BOOLEAN]);

        return $id;
    }

    /**
     * Sends JSON as an administrator.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        return $this->request($method, $path, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR), TestTokenFactory::staff(['admin']));
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

    private function assertViolation(string $field, string $title): void
    {
        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations']);
        $atField = array_values(array_filter($problem['violations'], static fn (mixed $violation): bool => \is_array($violation) && $field === $violation['propertyPath']));
        self::assertSame([['propertyPath' => $field, 'title' => $title]], $atField, json_encode($problem['violations'], \JSON_UNESCAPED_UNICODE) ?: '');
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
        $properties = $this->schema($schemaName)['properties'];
        self::assertIsArray($properties);
        self::assertEqualsCanonicalizing(array_keys($properties), array_keys($object), $schemaName.' keys must match the schema.');
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertKeysWithinSchema(array $problem, string $schemaName): void
    {
        $properties = $this->schema($schemaName)['properties'];
        self::assertIsArray($properties);
        self::assertSame([], array_values(array_diff(array_keys($problem), array_keys($properties))), $schemaName.' must describe every key.');
        self::assertIsArray($properties['type']);
        self::assertIsArray($properties['type']['enum']);
        self::assertContains($problem['type'], $properties['type']['enum']);
    }

    /**
     * @return array<mixed>
     */
    private function schema(string $schemaName): array
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName] ?? null;
        self::assertIsArray($schema, $schemaName.' must be in openapi-admin.json.');

        return $schema;
    }
}
