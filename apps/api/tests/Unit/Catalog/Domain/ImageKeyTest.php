<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ImageKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageKeyTest extends TestCase
{
    #[DataProvider('validExtensions')]
    public function testValidKeyIsAccepted(string $extension): void
    {
        $key = 'originals/'.str_repeat('a', 64).'.'.$extension;

        self::assertSame($key, ImageKey::fromString($key)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function validExtensions(): iterable
    {
        foreach (['jpg', 'png', 'webp', 'avif', 'heic'] as $extension) {
            yield $extension => [$extension];
        }
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
        yield 'jpeg extension' => ['originals/'.str_repeat('a', 64).'.jpeg'];
        yield 'svg extension' => ['originals/'.str_repeat('a', 64).'.svg'];
        yield 'gif extension' => ['originals/'.str_repeat('a', 64).'.gif'];
    }
}
