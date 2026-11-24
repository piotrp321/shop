<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Category\CategorySlug;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    private const string ROOT = '11111111-1111-4111-8111-111111111111';
    private const string CHILD = '22222222-2222-4222-8222-222222222222';
    private const string OTHER = '33333333-3333-4333-8333-333333333333';

    public function testRootHasPathWithOwnId(): void
    {
        $id = CategoryId::fromString('11111111-1111-4111-8111-111111111111');

        $root = Category::createRoot($id, CategoryName::of('Shoes'), new CategorySlug('shoes'));

        self::assertSame('shoes', $root->slug()->value());
        self::assertSame('/11111111-1111-4111-8111-111111111111/', $root->path()->toString());
        self::assertEquals([$id], $root->path()->ancestorIds());
        self::assertNull($root->parentId());
    }

    public function testGrandchildPathListsAncestorsFromRoot(): void
    {
        $r = CategoryId::fromString('11111111-1111-4111-8111-111111111111');
        $c = CategoryId::fromString('22222222-2222-4222-8222-222222222222');
        $g = CategoryId::fromString('33333333-3333-4333-8333-333333333333');
        $root = Category::createRoot($r, CategoryName::of('A'), new CategorySlug('a'));
        $child = Category::createChild($c, CategoryName::of('B'), new CategorySlug('b'), $root);

        $grandchild = Category::createChild($g, CategoryName::of('C'), new CategorySlug('c'), $child);

        self::assertSame('c', $grandchild->slug()->value());
        self::assertSame('/11111111-1111-4111-8111-111111111111/22222222-2222-4222-8222-222222222222/33333333-3333-4333-8333-333333333333/', $grandchild->path()->toString());
        self::assertEquals([$r, $c, $g], $grandchild->path()->ancestorIds());
        self::assertEquals($c, $grandchild->parentId());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategoryName::of('');
    }

    public function testWhitespaceNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategoryName::of("  \t");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithoutText(): iterable
    {
        yield 'NBSP only' => ["\u{00A0}\u{2003}"];
        yield 'a NUL' => ["Sport\0"];
        yield 'a line separator' => ["Sport\u{2028}"];
        yield 'a new line' => ["Sport\nZima"];
        yield 'invalid UTF-8' => ["Sport\xC3"];
        // The rule of texts (spec api-input-contract, T1 and T3).
        yield 'a C1 control character' => ["Spo\u{9F}rt"];
        yield 'a line separator removed by a trim' => ["\u{2028}Sport"];
        yield 'a zero width space only' => ["\u{200B}"];
        yield 'a byte order mark only' => ["\u{FEFF}"];
        yield 'a soft hyphen only' => ["\u{AD}"];
        yield 'a word joiner only' => ["\u{2060}"];
        yield 'a left-to-right mark only' => ["\u{200E}"];
        yield 'a right-to-left override only' => ["\u{202E}"];
        yield 'a Hangul filler only' => ["\u{3164}"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('namesWithoutText')]
    public function testANameOfWhitespaceOrWithControlCharactersIsRejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategoryName::of($name);
    }

    public function testANameIsTrimmedOfUnicodeWhitespace(): void
    {
        self::assertSame('Łyżwy', CategoryName::of("\u{00A0} Łyżwy \u{2003}")->value());
    }

    public function testANameIsTrimmedOfInvisibleCharactersAndKeepsAFlagAtItsEnd(): void
    {
        self::assertSame('Łyżwy', CategoryName::of("\u{200B}\u{FEFF}Łyżwy\u{2060}\u{AD}")->value());
        self::assertSame("Anglia \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", CategoryName::of("Anglia \u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}")->value());
    }

    public function testTheLengthIsCountedAfterTheTrim(): void
    {
        self::assertSame(str_repeat('ą', 100), CategoryName::of("\u{200B}".str_repeat('ą', 100)."\u{200B}")->value());
    }

    /**
     * A name stored before a stricter rule is read as it is (spec api-input-contract, E7).
     */
    public function testAStoredNameIsReconstitutedWithoutTheRule(): void
    {
        foreach (["\u{200B}", "Sport\tZima", "Spo\u{9F}rt", ' Sport '] as $stored) {
            self::assertSame($stored, CategoryName::reconstitute($stored)->value());
        }
    }

    public function testNameOf100CharactersIsAccepted(): void
    {
        self::assertSame(str_repeat('ą', 100), CategoryName::of(str_repeat('ą', 100))->value());
    }

    public function testNameOf101CharactersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategoryName::of(str_repeat('a', 101));
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
            CategoryName::of('Running'),
            new CategorySlug('running'),
            CategoryId::fromString(self::ROOT),
            CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'),
        );

        self::assertSame(self::CHILD, $category->id()->toString());
        self::assertSame('Running', $category->name()->value());
        self::assertSame('running', $category->slug()->value());
        self::assertSame(self::ROOT, $category->parentId()?->toString());
        self::assertSame('/'.self::ROOT.'/'.self::CHILD.'/', $category->path()->toString());
    }

    public function testReconstituteRestoresRoot(): void
    {
        $category = Category::reconstitute(
            CategoryId::fromString(self::ROOT),
            CategoryName::of('Shoes'),
            new CategorySlug('shoes'),
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
            CategoryName::of('Running'),
            new CategorySlug('running'),
            CategoryId::fromString(self::ROOT),
            CategoryPath::fromString('/'.self::ROOT.'/'.self::OTHER.'/'),
        );
    }

    public function testReconstituteRejectsParentOtherThanPenultimatePathElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::CHILD),
            CategoryName::of('Running'),
            new CategorySlug('running'),
            CategoryId::fromString(self::OTHER),
            CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'),
        );
    }

    public function testReconstituteRejectsChildPathWithoutParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::CHILD),
            CategoryName::of('Running'),
            new CategorySlug('running'),
            null,
            CategoryPath::fromString('/'.self::ROOT.'/'.self::CHILD.'/'),
        );
    }

    public function testReconstituteRejectsRootWithParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Category::reconstitute(
            CategoryId::fromString(self::ROOT),
            CategoryName::of('Shoes'),
            new CategorySlug('shoes'),
            CategoryId::fromString(self::OTHER),
            CategoryPath::fromString('/'.self::ROOT.'/'),
        );
    }
}
