<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http\Cache;

use App\Shared\Infrastructure\Http\Cache\CacheTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheTagsTest extends TestCase
{
    public function testTheProductTagHasTheIdInLowerCase(): void
    {
        self::assertSame('product-0192f0c4-7c1a-7c4e-9a6e-00000000000a', CacheTags::product('0192f0c4-7c1a-7c4e-9a6e-00000000000a'));
        self::assertSame('product-0192f0c4-7c1a-7c4e-9a6e-00000000000a', CacheTags::product('0192F0C4-7C1A-7C4E-9A6E-00000000000A'));
    }

    /**
     * A tag goes into a response header and into a PURGE: anything but a UUID is a programming error.
     *
     * @return iterable<string, array{string}>
     */
    public static function notUuids(): iterable
    {
        yield 'empty' => [''];
        yield 'slug' => ['buty-szosowe'];
        yield 'two tags in one' => ['0192f0c4-7c1a-7c4e-9a6e-00000000000a catalog'];
        yield 'header injection' => ["0192f0c4-7c1a-7c4e-9a6e-00000000000a\r\nX-Evil: 1"];
        yield 'braces' => ['{0192f0c4-7c1a-7c4e-9a6e-00000000000a}'];
        yield 'no hyphens' => ['0192f0c47c1a7c4e9a6e00000000000a'];
    }

    #[DataProvider('notUuids')]
    public function testTheProductTagRejectsAnythingButAUuid(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CacheTags::product($id);
    }

    public function testTheProductSlugTagHasTheSlug(): void
    {
        self::assertSame('product-slug-buty-szosowe-2', CacheTags::productSlug('buty-szosowe-2'));
        self::assertSame('product-slug-'.str_repeat('a', 200), CacheTags::productSlug(str_repeat('a', 200)));
    }

    /**
     * Only a slug in the format of Catalog's Slug names a product: anything else is a programming error, and could
     * otherwise inject a second tag or a header.
     *
     * @return iterable<string, array{string}>
     */
    public static function notSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Buty-szosowe'];
        yield 'Polish letter' => ['buty-różowe'];
        yield 'leading dash' => ['-buty'];
        yield 'trailing dash' => ['buty-'];
        yield 'double dash' => ['buty--szosowe'];
        yield 'two tags in one' => ['buty catalog'];
        yield 'trailing newline' => ["buty\n"];
        yield 'header injection' => ["buty\r\nX-Evil: 1"];
        yield 'slash' => ['buty/szosowe'];
        yield 'too long' => [str_repeat('a', 201)];
    }

    #[DataProvider('notSlugs')]
    public function testTheProductSlugTagRejectsAnythingButASlug(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CacheTags::productSlug($slug);
    }
}
