<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ProductSort;
use PHPUnit\Framework\TestCase;

final class ProductSearchCriteriaTest extends TestCase
{
    public function testSortDefaultsToNameWithoutAPhrase(): void
    {
        self::assertSame(ProductSort::Name, (new ProductSearchCriteria(1, 24))->sort());
    }

    public function testSortDefaultsToRelevanceWithAPhrase(): void
    {
        self::assertSame(ProductSort::Relevance, (new ProductSearchCriteria(1, 24, phrase: 'buty'))->sort());
    }

    public function testExplicitSortWins(): void
    {
        self::assertSame(ProductSort::PriceDesc, (new ProductSearchCriteria(1, 24, phrase: 'buty', sort: ProductSort::PriceDesc))->sort());
    }

    public function testRelevanceNeedsAPhrase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductSearchCriteria(1, 24, sort: ProductSort::Relevance);
    }

    public function testPageAndPerPageMustBePositive(): void
    {
        foreach ([[0, 24], [1, 0]] as [$page, $perPage]) {
            try {
                new ProductSearchCriteria($page, $perPage);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPriceRangeMustNotBeInverted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductSearchCriteria(1, 24, priceMin: 200, priceMax: 100);
    }

    public function testNegativePriceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductSearchCriteria(1, 24, priceMin: -1);
    }

    public function testVariantConditionExistsOnlyWithAnOptionAPriceOrAvailability(): void
    {
        self::assertFalse((new ProductSearchCriteria(1, 24, phrase: 'x', categoryId: 'c'))->hasVariantCondition());
        self::assertTrue((new ProductSearchCriteria(1, 24, options: ['Kolor' => ['czarne']]))->hasVariantCondition());
        self::assertTrue((new ProductSearchCriteria(1, 24, priceMin: 0))->hasVariantCondition());
        self::assertTrue((new ProductSearchCriteria(1, 24, priceMax: 5))->hasVariantCondition());
        self::assertTrue((new ProductSearchCriteria(1, 24, availableOnly: true))->hasVariantCondition());
    }
}
