<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\PersonDictionaries;
use PHPUnit\Framework\TestCase;

final class PersonDictionariesTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../fixtures/demo-data';

    private string $copy = '';

    protected function tearDown(): void
    {
        if ('' !== $this->copy) {
            foreach (glob($this->copy.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->copy);
        }
    }

    public function testTheDictionariesOfTheRepositoryHaveTheNamesOfTheRegisterOfEachKind(): void
    {
        $people = new PersonDictionaries(self::FIXTURES);

        foreach ([true, false] as $female) {
            self::assertCount(300, $people->firstNames($female)->names());
            self::assertCount(1000, $people->surnames($female)->names());
        }
        self::assertContains('Łukasz', $people->firstNames(false)->names());
        self::assertContains('Kowalska', $people->surnames(true)->names());
        self::assertContains('Kowalski', $people->surnames(false)->names());
        self::assertGreaterThanOrEqual(50, \count($people->cities()));
        self::assertGreaterThanOrEqual(50, \count($people->streets()));
        self::assertSame($people->streets(), array_values(array_unique($people->streets())), 'Every street is listed once.');
    }

    public function testEveryCityHasPostalCodesInThePolishFormat(): void
    {
        foreach (new PersonDictionaries(self::FIXTURES)->cities() as $city) {
            foreach ($city['postalCodes'] as $code) {
                self::assertMatchesRegularExpression('/^\d{2}-\d{3}$/D', $code, $city['name']);
            }
        }
    }

    public function testNamesInCapitalsAsInTheRegisterAreNormalised(): void
    {
        $this->copyFixtures();
        file_put_contents($this->copy.'/first-names.yaml', "male:\n  JAN: 10\n  ŁUKASZ: 5\nfemale:\n  ANNA: 7\n");
        file_put_contents($this->copy.'/surnames.yaml', "male:\n  NOWAK-KOWALSKI: 3\nfemale:\n  ŻÓŁĆ: 2\n");

        $people = new PersonDictionaries($this->copy);

        self::assertSame(['Jan', 'Łukasz'], $people->firstNames(false)->names());
        self::assertSame(['Anna'], $people->firstNames(true)->names());
        self::assertSame(['Nowak-Kowalski'], $people->surnames(false)->names());
        self::assertSame(['Żółć'], $people->surnames(true)->names());
    }

    /**
     * The generator writes the names, the streets and the cities of the people by COPY, past the rule of texts: every
     * entry of the dictionaries is in the form the rule keeps (see GeneratedText).
     */
    public function testEveryTextOfThePeopleDictionariesIsInTheFormOfTheRule(): void
    {
        $people = new PersonDictionaries(self::FIXTURES);

        foreach ([true, false] as $female) {
            GeneratedText::assertAllInTheFormOfTheRule($people->firstNames($female)->names(), 'first name');
            GeneratedText::assertAllInTheFormOfTheRule($people->surnames($female)->names(), 'surname');
        }
        GeneratedText::assertAllInTheFormOfTheRule(array_column($people->cities(), 'name'), 'city');
        GeneratedText::assertAllInTheFormOfTheRule($people->streets(), 'street');
    }

    public function testTheFingerprintIsStableAndChangesWithEveryDictionaryOfPeople(): void
    {
        $fingerprint = new PersonDictionaries(self::FIXTURES)->fingerprint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $fingerprint);
        self::assertSame($fingerprint, new PersonDictionaries(self::FIXTURES)->fingerprint());

        // The first entry of each dictionary: a name with its weight, a street.
        foreach (['first-names.yaml' => "  PIOTR: 682516\n", 'surnames.yaml' => "  NOWAK: 147672\n", 'places.yaml' => "  - Polna\n"] as $file => $line) {
            $this->copyFixtures();
            self::assertSame($fingerprint, new PersonDictionaries($this->copy)->fingerprint(), 'A copy has the same fingerprint.');

            $content = (string) file_get_contents($this->copy.'/'.$file);
            self::assertStringContainsString($line, $content);
            file_put_contents($this->copy.'/'.$file, str_replace($line, '', $content));

            self::assertNotSame($fingerprint, new PersonDictionaries($this->copy)->fingerprint(), $file);
            $this->tearDown();
            $this->copy = '';
        }
    }

    public function testCommentsDoNotChangeTheFingerprint(): void
    {
        $fingerprint = new PersonDictionaries(self::FIXTURES)->fingerprint();
        $this->copyFixtures();
        file_put_contents($this->copy.'/places.yaml', "# another comment\n".file_get_contents($this->copy.'/places.yaml'));

        self::assertSame($fingerprint, new PersonDictionaries($this->copy)->fingerprint());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'missing file' => ['first-names.yaml', '', 'first-names.yaml'];
        yield 'not YAML' => ['surnames.yaml', "male: [\n", 'surnames.yaml'];
        yield 'no female names' => ['first-names.yaml', "male:\n  Jan: 1\n", 'female'];
        yield 'weight zero' => ['first-names.yaml', "male:\n  Jan: 0\nfemale:\n  Anna: 1\n", 'Jan'];
        yield 'weight not a number' => ['surnames.yaml', "male:\n  Nowak: many\nfemale:\n  Nowak: 1\n", 'Nowak'];
        yield 'a name without a letter of a login' => ['surnames.yaml', "male:\n  '---': 1\nfemale:\n  Nowak: 1\n", '---'];
        yield 'a postal code in a bad format' => ['places.yaml', "cities:\n  Radom: { weight: 1, postalCodes: ['26600'] }\nstreets: [Polna]\n", 'Radom'];
        yield 'a city without a postal code' => ['places.yaml', "cities:\n  Radom: { weight: 1, postalCodes: [] }\nstreets: [Polna]\n", 'Radom'];
        yield 'no streets' => ['places.yaml', "cities:\n  Radom: { weight: 1, postalCodes: ['26-600'] }\nstreets: []\n", 'streets'];
    }

    /**
     * @dataProvider brokenFiles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiles')]
    public function testABrokenFileIsReportedByItsName(string $file, string $content, string $message): void
    {
        $this->copyFixtures();
        if ('' === $content) {
            unlink($this->copy.'/'.$file);
        } else {
            file_put_contents($this->copy.'/'.$file, $content);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PersonDictionaries($this->copy)->fingerprint();
    }

    private function copyFixtures(): void
    {
        $this->copy = sys_get_temp_dir().'/person-dictionaries-'.bin2hex(random_bytes(6));
        mkdir($this->copy);
        foreach (['first-names.yaml', 'surnames.yaml', 'places.yaml'] as $file) {
            copy(self::FIXTURES.'/'.$file, $this->copy.'/'.$file);
        }
    }
}
