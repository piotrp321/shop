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
            priceMin: 0,
            priceMax: 0,
            available: 'true',
            sort: 'price_desc',
            page: 1,
            perPage: 48,
        );

        self::assertSame([], $this->paths($query));
    }

    public function testPhraseIsTrimmedBeforeTheLengthIsChecked(): void
    {
        self::assertSame([], $this->paths(new ListProductsQuery(q: ' ab ')));
        self::assertSame(['q'], $this->paths(new ListProductsQuery(q: ' a ')));
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
        yield 'priceMin is negative' => [new ListProductsQuery(priceMin: -1), 'priceMin'];
        yield 'priceMax is negative' => [new ListProductsQuery(priceMax: -1), 'priceMax'];
        yield 'priceMin above priceMax' => [new ListProductsQuery(priceMin: 101, priceMax: 100), 'priceMin'];
        yield 'available is false' => [new ListProductsQuery(available: 'false'), 'available'];
        yield 'available is empty' => [new ListProductsQuery(available: ''), 'available'];
        yield 'available is a number' => [new ListProductsQuery(available: '1'), 'available'];
        yield 'sort is unknown' => [new ListProductsQuery(sort: 'popular'), 'sort'];
        yield 'sort is empty' => [new ListProductsQuery(sort: ''), 'sort'];
        yield 'relevance without a phrase' => [new ListProductsQuery(sort: 'relevance'), 'sort'];
        yield 'page is zero' => [new ListProductsQuery(page: 0), 'page'];
        yield 'perPage is zero' => [new ListProductsQuery(perPage: 0), 'perPage'];
        yield 'perPage over the maximum' => [new ListProductsQuery(perPage: 49), 'perPage'];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidParameterIsReportedOnItsOwnPath(ListProductsQuery $query, string $path): void
    {
        self::assertSame([$path], $this->paths($query));
    }

    public function testPhraseAndSortAreNormalized(): void
    {
        self::assertSame('buty', (new ListProductsQuery(q: '  buty '))->phrase());
        self::assertNull((new ListProductsQuery())->phrase());
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
