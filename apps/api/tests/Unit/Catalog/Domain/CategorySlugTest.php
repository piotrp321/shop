<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategorySlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorySlugTest extends TestCase
{
    #[DataProvider('validSlugs')]
    public function testValidSlugIsAccepted(string $slug): void
    {
        self::assertSame($slug, new CategorySlug($slug)->value());
    }

    /** @return iterable<string, array{string}> */
    public static function validSlugs(): iterable
    {
        yield 'one character' => ['a'];
        yield 'one digit' => ['7'];
        yield 'words with single dashes' => ['kaski-i-ochraniacze'];
        yield 'letters and digits' => ['2-osobowe'];
        yield '60 characters' => [str_repeat('a', 60)];
        yield 'uuid' => ['00000000-0000-4000-8000-000000000001'];
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugIsRejected(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CategorySlug($slug);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield '61 characters' => [str_repeat('a', 61)];
        yield 'uppercase' => ['Buty'];
        yield 'polish letter' => ['żywność'];
        yield 'leading dash' => ['-buty'];
        yield 'trailing dash' => ['buty-'];
        yield 'double dash' => ['buty--szosowe'];
        yield 'space' => ['buty szosowe'];
        yield 'slash' => ['bieganie/buty'];
        yield 'underscore' => ['buty_szosowe'];
        yield 'trailing newline' => ["buty\n"];
    }

    public function testSlugsWithTheSameValueAreEqual(): void
    {
        self::assertTrue(new CategorySlug('buty')->equals(new CategorySlug('buty')));
        self::assertFalse(new CategorySlug('buty')->equals(new CategorySlug('namioty')));
    }
}
