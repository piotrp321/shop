<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryPath;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    private const string ROOT = '11111111-1111-4111-8111-111111111111';
    private const string CHILD = '22222222-2222-4222-8222-222222222222';
    private const string OTHER = '33333333-3333-4333-8333-333333333333';

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
        $path = CategoryPath::fromString('/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222/');

        self::assertSame('/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222/', $path->toString());
    }

    public function testMalformedPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategoryPath::fromString('11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222');
    }

    public function testReconstituteRestoresChild(): void
    {
        $category = Category::reconstitute(
            CategoryId::fromString(self::CHILD),
            new CategoryName('Running'),
            CategoryId::fromString(self::ROOT),
            CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'),
        );

        self::assertSame(self::CHILD, $category->id()->toString());
        self::assertSame('Running', $category->name()->value());
        self::assertSame(self::ROOT, $category->parentId()?->toString());
        self::assertSame('/'.self::ROOT.'/'.self::CHILD.'/', $category->path()->toString());
    }

    public function testReconstituteRestoresRoot(): void
    {
        $category = Category::reconstitute(
            CategoryId::fromString(self::ROOT),
            new CategoryName('Shoes'),
            null,
            CategoryPath::fromString('/'.self::ROOT.'/'),
        );

        self::assertNull($category->parentId());
        self::assertSame('/'.self::ROOT.'/', $category->path()->toString());
    }

    public function testReconstituteRejectsPathNotEndingWithOwnId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::CHILD),
            new CategoryName('Running'),
            CategoryId::fromString(self::ROOT),
            CategoryPath::fromString('/'.self::ROOT.'/'.self::OTHER.'/'),
        );
    }

    public function testReconstituteRejectsParentOtherThanPenultimatePathElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::CHILD),
            new CategoryName('Running'),
            CategoryId::fromString(self::OTHER),
            CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'),
        );
    }

    public function testReconstituteRejectsChildPathWithoutParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::CHILD),
            new CategoryName('Running'),
            null,
            CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'),
        );
    }

    public function testReconstituteRejectsRootWithParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::ROOT),
            new CategoryName('Shoes'),
            CategoryId::fromString(self::OTHER),
            CategoryPath::fromString('/'.self::ROOT.'/'),
        );
    }
}
