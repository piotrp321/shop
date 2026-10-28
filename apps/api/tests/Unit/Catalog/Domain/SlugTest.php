<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testValidSlugIsAccepted(): void
    {
        self::assertSame('buty-puls-2', (new Slug('buty-puls-2'))->value());
    }

    public function testSlugOf200CharactersIsAccepted(): void
    {
        self::assertSame(200, \strlen((new Slug(str_repeat('a', 200)))->value()));
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugIsRejected(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Slug($slug);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'uppercase' => ['Buty'];
        yield 'leading dash' => ['-a'];
        yield 'trailing dash' => ['a-'];
        yield 'double dash' => ['a--b'];
        yield 'space' => ['a b'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 201)];
    }
}
