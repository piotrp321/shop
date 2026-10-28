<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ProductCategories;
use PHPUnit\Framework\TestCase;

final class ProductCategoriesTest extends TestCase
{
    public function testMainOnly(): void
    {
        $main = CategoryId::fromString('11111111-1111-4111-8111-111111111111');

        $categories = ProductCategories::create($main);

        self::assertEquals([$main], $categories->all());
        self::assertEquals($main, $categories->main());
        self::assertSame([], $categories->additional());
    }

    public function testMainComesFirstThenAdditionalInGivenOrder(): void
    {
        $main = CategoryId::fromString('11111111-1111-4111-8111-111111111111');
        $a = CategoryId::fromString('22222222-2222-4222-8222-222222222222');
        $b = CategoryId::fromString('33333333-3333-4333-8333-333333333333');

        $categories = ProductCategories::create($main, [$a, $b]);

        self::assertEquals([$main, $a, $b], $categories->all());
        self::assertEquals([$a, $b], $categories->additional());
    }

    public function testRepeatedAdditionalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductCategories::create(CategoryId::fromString('11111111-1111-4111-8111-111111111111'), [CategoryId::fromString('22222222-2222-4222-8222-222222222222'), CategoryId::fromString('22222222-2222-4222-8222-222222222222')]);
    }

    public function testMainRepeatedAsAdditionalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductCategories::create(CategoryId::fromString('11111111-1111-4111-8111-111111111111'), [CategoryId::fromString('11111111-1111-4111-8111-111111111111')]);
    }

    public function testTenAdditionalCategoriesAreAccepted(): void
    {
        $main = CategoryId::fromString('11111111-1111-4111-8111-111111111111');

        $categories = ProductCategories::create($main, $this->additionalIds(10));

        self::assertCount(11, $categories->all());
        self::assertSame($main, $categories->all()[0]);
    }

    public function testElevenAdditionalCategoriesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductCategories::create(CategoryId::fromString('11111111-1111-4111-8111-111111111111'), $this->additionalIds(11));
    }

    /**
     * @return list<CategoryId>
     */
    private function additionalIds(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; ++$i) {
            $ids[] = CategoryId::fromString(\sprintf('00000000-0000-4000-8000-%012d', $i));
        }

        return $ids;
    }
}
