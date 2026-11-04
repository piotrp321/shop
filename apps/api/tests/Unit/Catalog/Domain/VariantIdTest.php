<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\VariantId;
use PHPUnit\Framework\TestCase;

final class VariantIdTest extends TestCase
{
    public function testUuidIsAcceptedAndLowercased(): void
    {
        $id = VariantId::fromString('AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA');

        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $id->toString());
        self::assertTrue($id->equals(VariantId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')));
        self::assertFalse($id->equals(VariantId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')));
    }

    public function testNonUuidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VariantId::fromString('not-a-uuid');
    }
}
