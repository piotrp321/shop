<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Infrastructure\Demo\YamlDemoDiscountCodeSource;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real file fixtures/discount-codes.yaml: the three demo codes the spec names.
 */
final class RealDemoDiscountCodesTest extends TestCase
{
    public function testTheFileHasTheThreeDemoCodes(): void
    {
        $list = new YamlDemoDiscountCodeSource(__DIR__.'/../../../../fixtures/discount-codes.yaml')->load();

        $byCode = [];
        foreach ($list->codes() as $code) {
            $byCode[$code->code] = $code;
        }

        self::assertSame(['WITAJ10', 'MINUS50', 'LATO15'], array_keys($byCode));
        self::assertSame([DiscountKind::Percent, 10, null, 0, null], [$byCode['WITAJ10']->kind, $byCode['WITAJ10']->value, $byCode['WITAJ10']->minimumOrderGross, $byCode['WITAJ10']->startsInDays, $byCode['WITAJ10']->endsInDays]);
        self::assertSame([DiscountKind::Amount, 5000, 30000, 0, null], [$byCode['MINUS50']->kind, $byCode['MINUS50']->value, $byCode['MINUS50']->minimumOrderGross, $byCode['MINUS50']->startsInDays, $byCode['MINUS50']->endsInDays]);
        self::assertSame([DiscountKind::Percent, 15, null, -60, -30], [$byCode['LATO15']->kind, $byCode['LATO15']->value, $byCode['LATO15']->minimumOrderGross, $byCode['LATO15']->startsInDays, $byCode['LATO15']->endsInDays]);
    }
}
