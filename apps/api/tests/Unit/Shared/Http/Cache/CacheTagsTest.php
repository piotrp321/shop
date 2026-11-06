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
}
