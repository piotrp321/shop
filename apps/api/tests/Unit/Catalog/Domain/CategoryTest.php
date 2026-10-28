<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testRootHasPathWithOwnId(): void
    {
        $id = CategoryId::fromString('11111111-1111-4111-8111-111111111111');

        $root = Category::createRoot($id, new CategoryName('Shoes'));

        self::assertSame('/11111111-1111-4111-8111-111111111111/', $root->path()->toString());
        self::assertEquals([$id], $root->path()->ancestorIds());
        self::assertNull($root->parentId());
    }

    public function testGrandchildPathListsAncestorsFromRoot(): void
    {
        $r = CategoryId::fromString('11111111-1111-4111-8111-111111111111');
        $c = CategoryId::fromString('22222222-2222-4222-8222-222222222222');
        $g = CategoryId::fromString('33333333-3333-4333-8333-333333333333');
        $root = Category::createRoot($r, new CategoryName('A'));
        $child = Category::createChild($c, new CategoryName('B'), $root);

        $grandchild = Category::createChild($g, new CategoryName('C'), $child);

        self::assertSame('/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222/33333333-3333-4333-8333-333333333333/', $grandchild->path()->toString());
        self::assertEquals([$r, $c, $g], $grandchild->path()->ancestorIds());
        self::assertEquals($c, $grandchild->parentId());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CategoryName('');
    }

    public function testWhitespaceNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CategoryName("  \t");
    }

    public function testNameOf100CharactersIsAccepted(): void
    {
        self::assertSame(str_repeat('ą', 100), (new CategoryName(str_repeat('ą', 100)))->value());
    }

    public function testNameOf101CharactersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CategoryName(str_repeat('a', 101));
    }

    public function testPathRoundTripsThroughString(): void
    {
        $path = \App\Catalog\Domain\Category\CategoryPath::fromString('/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222/');

        self::assertSame('/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222/', $path->toString());
    }

    public function testMalformedPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \App\Catalog\Domain\Category\CategoryPath::fromString('11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222');
    }
}
