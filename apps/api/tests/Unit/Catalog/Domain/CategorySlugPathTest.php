<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\CategorySlugPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorySlugPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'one slug' => ['sport'];
        yield 'four slugs' => ['sport/bieganie/buty/trail-2'];
        yield 'a slug of 60 characters' => [str_repeat('a', 60)];
    }

    #[DataProvider('valid')]
    public function testOneToFourSlugsJoinedWithSlashesArePaths(string $path): void
    {
        self::assertSame($path, CategorySlugPath::fromString($path)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'five slugs' => ['a/b/c/d/e'];
        yield 'a leading slash' => ['/sport'];
        yield 'a trailing slash' => ['sport/'];
        yield 'an empty segment' => ['sport//buty'];
        yield 'upper case' => ['Sport'];
        yield 'polish letters' => ['łyżwy'];
        yield 'a slug of 61 characters' => [str_repeat('a', 61)];
        yield 'a space' => ['sport buty'];
        yield 'a new line at the end' => ["sport\n"];
        yield 'a NUL' => ["sport\0"];
        yield 'dots' => ['sport/../buty'];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsRefused(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CategorySlugPath::fromString($path);
    }

    public function testAChildPathAddsASlug(): void
    {
        $path = CategorySlugPath::root(new CategorySlug('sport'))->child(new CategorySlug('buty'));

        self::assertSame('sport/buty', $path->toString());
    }
}
