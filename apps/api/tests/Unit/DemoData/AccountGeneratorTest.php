<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Accounts\Transliteration;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Identity\StaffRoles;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\ShippingAddress;
use PHPUnit\Framework\TestCase;

final class AccountGeneratorTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../fixtures/demo-data';
    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';
    private const string OTHER_NAMESPACE = 'b3a7d1f0-3c64-4e8b-9a52-1d7e0c5f6a49';

    private static ?PersonDictionaries $people = null;

    public static function tearDownAfterClass(): void
    {
        self::$people = null;
    }

    private static function people(): PersonDictionaries
    {
        return self::$people ??= new PersonDictionaries(self::FIXTURES);
    }

    private static function generator(string $namespace = self::NAMESPACE): AccountGenerator
    {
        return new AccountGenerator($namespace, self::people());
    }

    public function testTheSameKeyGivesTheSameAccount(): void
    {
        $first = self::generator();
        $second = self::generator();

        foreach ([1, 2, 500, 1000, 999_999] as $number) {
            self::assertEquals($first->customer($number), $second->customer($number));
            self::assertEquals($first->staff($number), $second->staff($number));
        }
    }

    public function testAnotherSeedGivesOtherPeople(): void
    {
        $same = 0;
        foreach (range(1, 200) as $number) {
            $same += self::generator()->customer($number)->username === self::generator(self::OTHER_NAMESPACE)->customer($number)->username ? 1 : 0;
        }

        self::assertLessThan(5, $same);
    }

    public function testTheCustomerUsernameIsTheEmailInTheGeneratorFormatWithoutPolishCharacters(): void
    {
        $domains = [];
        foreach (range(1, 1000) as $number) {
            $account = self::generator()->customer($number);

            self::assertNotNull($account->email);
            self::assertSame($account->email, $account->username);
            self::assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*\.[a-z]+(-[a-z]+)*\.'.$number.'@example\.(com|net|org)$/D', $account->email);
            self::assertSame(Transliteration::login($account->firstName).'.'.Transliteration::login($account->lastName).'.'.$number, explode('@', $account->email)[0]);
            self::assertNull($account->role);
            $domains[explode('@', $account->email)[1]] = true;
        }

        self::assertEqualsCanonicalizing(['example.com', 'example.net', 'example.org'], array_keys($domains));
    }

    public function testEveryGeneratedEmailIsInADomainReservedForExamples(): void
    {
        foreach ([self::NAMESPACE, self::OTHER_NAMESPACE] as $namespace) {
            $generator = self::generator($namespace);
            foreach ([...range(1, 2000), 1_000_000] as $number) {
                self::assertMatchesRegularExpression('/@example\.(com|net|org)$/D', (string) $generator->customer($number)->email);
                self::assertMatchesRegularExpression('/@example\.(com|net|org)$/D', $generator->person('guest', $number)->email('g'.$number));
                $staffEmail = $generator->staff($number)->email;
                self::assertTrue(null === $staffEmail || 1 === preg_match('/@example\.(com|net|org)$/D', $staffEmail));
            }
        }
    }

    public function testTheStaffUsernameIsFirstLastNumberAndTheRoleIsAFunctionOfTheNumber(): void
    {
        foreach ([...range(1, 520), 1000] as $number) {
            $account = self::generator()->staff($number);

            self::assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*\.[a-z]+(-[a-z]+)*\.'.$number.'$/D', $account->username);
            self::assertNull($account->email);
            self::assertSame(StaffRoles::roleOf($number), $account->role);
        }
    }

    public function testTheNamesKeepThePolishCharactersAndComeFromTheDictionaryOfTheSex(): void
    {
        $polish = 0;
        foreach (range(1, 1000) as $number) {
            $person = self::generator()->customer($number)->person;
            $female = $person->female;

            self::assertContains($person->firstName, self::people()->firstNames($female)->names());
            self::assertContains($person->lastName, self::people()->surnames($female)->names());
            $polish += 1 === preg_match('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $person->firstName.$person->lastName) ? 1 : 0;
        }

        self::assertGreaterThan(100, $polish, 'Many names have Polish letters.');
    }

    public function testAboutHalfOfThePeopleAreWomen(): void
    {
        $women = 0;
        foreach (range(1, 2000) as $number) {
            $women += self::generator()->customer($number)->person->female ? 1 : 0;
        }

        self::assertGreaterThan(800, $women);
        self::assertLessThan(1200, $women);
    }

    public function testTheContactAndTheAddressAreValidForAnOrder(): void
    {
        $cities = [];
        foreach (self::people()->cities() as $city) {
            $cities[$city['name']] = $city['postalCodes'];
        }
        foreach (range(1, 1000) as $number) {
            $account = self::generator()->customer($number);
            $person = $account->person;

            self::assertMatchesRegularExpression('/^\+48[0-9]{9}$/D', $person->phone);
            self::assertSame($person->phone, Contact::of(EmailAddress::fromString((string) $account->email), $person->fullName(), $person->phone)->phone());
            ShippingAddress::inPoland($person->street, $person->postalCode, $person->city);
            self::assertContains($person->postalCode, $cities[$person->city] ?? [], $person->city);
        }
    }

    public function testTheIdIsTheUuidV5OfTheKindTheFingerprintAndTheNumber(): void
    {
        $generator = self::generator();
        $fingerprint = self::people()->fingerprint();

        self::assertSame(DemoUuid::v5(self::NAMESPACE, 'customer:'.$fingerprint.':7'), $generator->customer(7)->id);
        self::assertSame(DemoUuid::v5(self::NAMESPACE, 'staff:'.$fingerprint.':7'), $generator->staff(7)->id);
        self::assertSame($generator->customer(7)->id, $generator->customerId(7));
        self::assertSame($generator->staff(7)->id, $generator->staffId(7));
        self::assertNotSame($generator->customerId(7), $generator->staffId(7));
        self::assertNotSame($generator->customerId(7), self::generator(self::OTHER_NAMESPACE)->customerId(7));
    }

    public function testAnotherFingerprintGivesOtherIds(): void
    {
        $copy = sys_get_temp_dir().'/account-generator-'.bin2hex(random_bytes(6));
        mkdir($copy);
        try {
            foreach (['first-names.yaml', 'surnames.yaml', 'places.yaml'] as $file) {
                copy(self::FIXTURES.'/'.$file, $copy.'/'.$file);
            }
            file_put_contents($copy.'/surnames.yaml', str_replace("  Mróz: 11500\n", '', (string) file_get_contents($copy.'/surnames.yaml')));
            $other = new AccountGenerator(self::NAMESPACE, new PersonDictionaries($copy));

            self::assertNotSame(self::generator()->customerId(1), $other->customerId(1));
            self::assertNotSame(self::generator()->staffId(1), $other->staffId(1));
        } finally {
            array_map('unlink', glob($copy.'/*') ?: []);
            rmdir($copy);
        }
    }

    public function testNumbersStartAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::generator()->customer(0);
    }

    public function testTheTransliterationDropsThePolishLettersAndEverythingButLettersAndHyphens(): void
    {
        self::assertSame('lukasz', Transliteration::login('Łukasz'));
        self::assertSame('zolc-sleczka', Transliteration::login('Żółć-Ślęczka'));
        self::assertSame('aceeoszzn', Transliteration::login("ĄĆĘę ÓŚŹŻŃ'"));
        self::assertSame('', Transliteration::login('---'));
    }
}
