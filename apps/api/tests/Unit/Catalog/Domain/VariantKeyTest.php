<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use PHPUnit\Framework\TestCase;

final class VariantKeyTest extends TestCase
{
    public function testDefaultVariantKeyIsTheProductSlugAndStandard(): void
    {
        self::assertSame('buty-szosowe-asfalt-200/standard', VariantKey::of('buty-szosowe-asfalt-200', self::values([])));
    }

    public function testKeyIsTheProductSlugAndTheSlugOfTheLabel(): void
    {
        self::assertSame('buty/czarne-42', VariantKey::of('buty', self::values(['czarne', '42'])));
        self::assertSame('kurtka/zolty-zolc-xl', VariantKey::of('kurtka', self::values(['Żółty', 'Żółć XL'])));
        self::assertSame('kajak/l-1', VariantKey::of('kajak', self::values(['  L  ', '+1'])));
    }

    /**
     * @param list<string> $values
     */
    private static function values(array $values): VariantValues
    {
        return VariantValues::fromList($values);
    }
}
