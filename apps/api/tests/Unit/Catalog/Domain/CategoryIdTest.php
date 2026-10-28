<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ProductId;
use PHPUnit\Framework\TestCase;

final class CategoryIdTest extends TestCase
{
    public function testUppercaseUuidIsStoredLowercase(): void
    {
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', CategoryId::fromString('AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE')->toString());
    }

    public function testNonUuidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategoryId::fromString('abc');
    }

    public function testProductIdRejectsNonUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductId::fromString('abc');
    }

    public function testProductIdIsStoredLowercase(): void
    {
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', ProductId::fromString('AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE')->toString());
    }
}
