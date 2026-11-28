<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Http;

use App\Catalog\UI\Http\ListProductsQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ListProductsQueryTest extends TestCase
{
    public function testDefaultsAreValid(): void
    {
        self::assertSame([], $this->paths(new ListProductsQuery()));
    }

    public function testEveryParameterAtItsLimitIsValid(): void
    {
        $query = new ListProductsQuery(
            category: '00000000-0000-4000-8000-0000000000a1',
            q: '  '.str_repeat('ż', 100).'  ',
            priceMin: '0',
            priceMax: '999999999999',
            available: 'true',
            sort: 'price_desc',
        );
        $query->page = 1;
        $query->perPage = 48;

        self::assertSame([], $this->paths($query));
    }

    public function testPhraseIsTrimmedBeforeTheLengthIsChecked(): void
    {
        self::assertSame([], $this->paths(new ListProductsQuery(q: ' ab ')));
        self::assertSame(['q'], $this->paths(new ListProductsQuery(q: ' a ')));
    }

    /**
     * The rule of texts (spec api-input-contract, T1–T4, E6): a phrase without a visible character is too short, a
     * forbidden character is refused, and the length counts after the trim of white space and invisible characters.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function phrasesOutsideTheRule(): iterable
    {
        $tooShort = 'This value is too short. It should have 2 characters or more.';
        $forbidden = 'Szukana fraza zawiera niedozwolone znaki.';
        yield 'two NBSP' => ["\u{A0}\u{A0}", $tooShort];
        yield 'three U+200B' => ["\u{200B}\u{200B}\u{200B}", $tooShort];
        yield 'three U+3164' => ["\u{3164}\u{3164}\u{3164}", $tooShort];
        yield 'one letter between U+200B' => ["\u{200B}a\u{200B}", $tooShort];
        yield 'a NUL inside' => ["a\u{0}b", $forbidden];
        yield 'U+009F inside' => ["a\u{9F}b", $forbidden];
        yield 'U+2028 inside' => ["a\u{2028}b", $forbidden];
        yield 'U+2028 at the end' => ["ab\u{2028}", $forbidden];
        yield 'a tab at the start' => ["\tab", $forbidden];
    }

    #[DataProvider('phrasesOutsideTheRule')]
    public function testAPhraseOutsideTheRuleIsOneViolationOfQ(string $phrase, string $message): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = [];
        foreach ($validator->validate(new ListProductsQuery(q: $phrase)) as $violation) {
            $violations[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
        }

        self::assertSame([['q', $message]], $violations);
    }

    public function testTheLengthOfAPhraseIsCountedAfterTheTrim(): void
    {
        self::assertSame([], $this->paths(new ListProductsQuery(q: "\u{200B}\u{200B}".str_repeat('ż', 100)."\u{FEFF}\u{A0}")));
    }

    public function testThePhraseIsTrimmedOfWhiteSpaceAndInvisibleCharacters(): void
    {
        self::assertSame('buty', (new ListProductsQuery(q: "\u{200B}\u{A0}buty\u{3000}\u{FEFF}"))->phrase());
        self::assertSame("bu\u{200B}ty", (new ListProductsQuery(q: "bu\u{200B}ty"))->phrase());
    }

    public function testRelevanceIsValidWithAPhrase(): void
    {
        self::assertSame([], $this->paths(new ListProductsQuery(q: 'buty', sort: 'relevance')));
    }

    /**
     * @return iterable<string, array{ListProductsQuery, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'category is not a UUID' => [new ListProductsQuery(category: 'abc'), 'category'];
        yield 'category is empty' => [new ListProductsQuery(category: ''), 'category'];
        yield 'phrase is empty' => [new ListProductsQuery(q: ''), 'q'];
        yield 'phrase is blank' => [new ListProductsQuery(q: '   '), 'q'];
        yield 'phrase is one character' => [new ListProductsQuery(q: 'a'), 'q'];
        yield 'phrase is 101 characters' => [new ListProductsQuery(q: str_repeat('ż', 101)), 'q'];
        yield 'priceMin is negative' => [new ListProductsQuery(priceMin: '-1'), 'priceMin'];
        yield 'priceMax is negative' => [new ListProductsQuery(priceMax: '-1'), 'priceMax'];
        yield 'priceMin is not a number' => [new ListProductsQuery(priceMin: 'cheap'), 'priceMin'];
        yield 'priceMax is a decimal' => [new ListProductsQuery(priceMax: '10.5'), 'priceMax'];
        yield 'priceMin is empty' => [new ListProductsQuery(priceMin: ''), 'priceMin'];
        yield 'priceMax has a sign' => [new ListProductsQuery(priceMax: '+5'), 'priceMax'];
        yield 'priceMin has a trailing newline' => [new ListProductsQuery(priceMin: "10\n"), 'priceMin'];
        yield 'priceMax has a trailing newline' => [new ListProductsQuery(priceMax: "10\n"), 'priceMax'];
        yield 'priceMin is 13 digits' => [new ListProductsQuery(priceMin: '1000000000000'), 'priceMin'];
        yield 'priceMin above priceMax' => [new ListProductsQuery(priceMin: '101', priceMax: '100'), 'priceMin'];
        yield 'available is false' => [new ListProductsQuery(available: 'false'), 'available'];
        yield 'available is empty' => [new ListProductsQuery(available: ''), 'available'];
        yield 'available is a number' => [new ListProductsQuery(available: '1'), 'available'];
        yield 'sort is unknown' => [new ListProductsQuery(sort: 'popular'), 'sort'];
        yield 'sort is empty' => [new ListProductsQuery(sort: ''), 'sort'];
        yield 'relevance without a phrase' => [new ListProductsQuery(sort: 'relevance'), 'sort'];
        yield 'page is zero' => [self::paged(0, 24), 'page'];
        yield 'perPage is zero' => [self::paged(1, 0), 'perPage'];
        yield 'perPage over the maximum' => [self::paged(1, 49), 'perPage'];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidParameterIsReportedOnItsOwnPath(ListProductsQuery $query, string $path): void
    {
        self::assertSame([$path], $this->paths($query));
    }

    public function testPricesAreIntegers(): void
    {
        $query = new ListProductsQuery(priceMin: '007', priceMax: '1500');

        self::assertSame(7, $query->priceMin());
        self::assertSame(1500, $query->priceMax());
        self::assertNull((new ListProductsQuery())->priceMin());
        self::assertNull((new ListProductsQuery())->priceMax());
    }

    public function testPhraseAndSortAreNormalized(): void
    {
        self::assertSame('buty', (new ListProductsQuery(q: '  buty '))->phrase());
        self::assertNull((new ListProductsQuery())->phrase());
    }

    private static function paged(int $page, int $perPage): ListProductsQuery
    {
        $query = new ListProductsQuery();
        $query->page = $page;
        $query->perPage = $perPage;

        return $query;
    }

    /**
     * @return list<string>
     */
    private function paths(ListProductsQuery $query): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $paths = [];
        foreach ($validator->validate($query) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
