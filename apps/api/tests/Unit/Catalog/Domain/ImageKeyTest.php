<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ImageKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageKeyTest extends TestCase
{
    public function testValidKeyIsAccepted(): void
    {
        self::assertSame('originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg', ImageKey::fromString('originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg')->toString());
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyIsRejected(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImageKey::fromString($key);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'short hash' => ['originals/abc.jpg'];
        yield 'traversal' => ['../x.jpg'];
        yield 'trailing newline' => ['originals/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg'."\n"];
        yield 'uppercase hash' => ['originals/'.str_repeat('A', 64).'.jpg'];
        yield 'wrong extension' => ['originals/'.str_repeat('a', 64).'.png'];
    }
}
