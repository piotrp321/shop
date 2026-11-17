<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Copy\CopyRow;
use PHPUnit\Framework\TestCase;

final class CopyRowTest extends TestCase
{
    public function testFieldsAreSeparatedByATabAndNullIsBackslashN(): void
    {
        self::assertSame("a\t1\t\\N\tt\tf", CopyRow::line(['a', 1, null, true, false]));
    }

    public function testSpecialCharactersAreEscapedForTheTextFormat(): void
    {
        self::assertSame('a\\\\b\\tc\\nd\\re', CopyRow::line(["a\\b\tc\nd\re"]));
    }

    public function testPolishLettersAndJsonPassThroughUnchanged(): void
    {
        $json = json_encode(['zażółć', 'a"b'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        self::assertSame('Żółć'."\t".str_replace('\\', '\\\\', $json), CopyRow::line(['Żółć', $json]));
    }

    public function testATimeIsWrittenInUtcWithTheOffset(): void
    {
        $at = new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('Europe/Warsaw'));

        self::assertSame('2026-03-04 04:06:07+00:00', CopyRow::line([$at]));
    }

    public function testAFloatIsWrittenWithoutALocale(): void
    {
        self::assertSame('1.5', CopyRow::line([1.5]));
    }

    public function testAnUnsupportedValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CopyRow::line([new \stdClass()]);
    }

    public function testANulCharacterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CopyRow::line(["a\0b"]);
    }
}
