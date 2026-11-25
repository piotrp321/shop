<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Http;

use App\Catalog\UI\Http\BackofficeProductsQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * The phrase `q` of the product list of the panel under the rule of texts (spec api-input-contract, T1–T4, E6).
 */
final class BackofficeProductsQueryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phrasesOutsideTheRule(): iterable
    {
        $tooShort = 'This value is too short. It should have 2 characters or more.';
        yield 'three ASCII spaces' => ['   ', $tooShort];
        yield 'two NBSP' => ["\u{A0}\u{A0}", $tooShort];
        yield 'three U+200B' => ["\u{200B}\u{200B}\u{200B}", $tooShort];
        yield 'three U+3164' => ["\u{3164}\u{3164}\u{3164}", $tooShort];
        yield 'one letter between NBSP' => ["\u{A0}a\u{A0}", $tooShort];
        yield 'a NUL inside' => ["a\u{0}b", BackofficeProductsQuery::BAD_TERM];
        yield 'U+009F inside' => ["a\u{9F}b", BackofficeProductsQuery::BAD_TERM];
        yield 'U+2028 inside' => ["a\u{2028}b", BackofficeProductsQuery::BAD_TERM];
        yield 'U+2028 at the end' => ["ab\u{2028}", BackofficeProductsQuery::BAD_TERM];
    }

    #[DataProvider('phrasesOutsideTheRule')]
    public function testAPhraseOutsideTheRuleIsOneViolationOfQ(string $phrase, string $message): void
    {
        self::assertSame([['q', $message]], $this->violations(new BackofficeProductsQuery(q: $phrase)));
    }

    public function testTheLengthOfAPhraseIsCountedAfterTheTrim(): void
    {
        self::assertSame([], $this->violations(new BackofficeProductsQuery(q: "\u{A0}\u{200B}".str_repeat('ż', 100)."\u{200B}")));
        self::assertSame([], $this->violations(new BackofficeProductsQuery(q: "\u{200B}ab\u{200B}")));
    }

    public function testTheListSearchesForTheTrimmedPhrase(): void
    {
        self::assertSame('bidon', new BackofficeProductsQuery(q: "\u{200B}\u{A0}bidon \u{FEFF}")->term());
        self::assertSame("bi\u{A0}don", new BackofficeProductsQuery(q: "bi\u{A0}don")->term());
        self::assertNull(new BackofficeProductsQuery()->term());
    }

    /**
     * @return list<array{string, string}>
     */
    private function violations(BackofficeProductsQuery $query): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $violations = [];
        foreach ($validator->validate($query) as $violation) {
            $violations[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
        }

        return $violations;
    }
}
