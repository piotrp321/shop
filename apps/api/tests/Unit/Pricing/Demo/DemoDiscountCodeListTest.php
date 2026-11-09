<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Demo;

use App\Pricing\Application\Demo\DemoDiscountCodeList;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoDiscountCodeListTest extends TestCase
{
    public function testKeepsTheCodesInOrderWithTheTextNormalised(): void
    {
        $list = new DemoDiscountCodeList([
            ['code' => ' witaj10 ', 'kind' => DiscountKind::Percent, 'value' => 10, 'minimumOrderGross' => null, 'startsInDays' => 0, 'endsInDays' => null],
            ['code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5000, 'minimumOrderGross' => 30000, 'startsInDays' => -2, 'endsInDays' => 10],
        ]);

        [$welcome, $minus] = $list->codes();

        self::assertSame(['WITAJ10', DiscountKind::Percent, 10, null, 0, null], [$welcome->code, $welcome->kind, $welcome->value, $welcome->minimumOrderGross, $welcome->startsInDays, $welcome->endsInDays]);
        self::assertSame(['MINUS50', DiscountKind::Amount, 5000, 30000, -2, 10], [$minus->code, $minus->kind, $minus->value, $minus->minimumOrderGross, $minus->startsInDays, $minus->endsInDays]);
    }

    public function testAnExpiredPeriodIsAllowed(): void
    {
        $list = new DemoDiscountCodeList([['code' => 'LATO15', 'kind' => DiscountKind::Percent, 'value' => 15, 'minimumOrderGross' => null, 'startsInDays' => -60, 'endsInDays' => -30]]);

        self::assertCount(1, $list->codes());
    }

    /**
     * @param list<array{code: string, kind: DiscountKind, value: int, minimumOrderGross: int|null, startsInDays: int, endsInDays: int|null}> $codes
     */
    #[DataProvider('invalidLists')]
    public function testInvalidListIsRejectedWithTheCodeNamed(array $codes, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new DemoDiscountCodeList($codes);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function invalidLists(): iterable
    {
        $percent = ['code' => 'WITAJ10', 'kind' => DiscountKind::Percent, 'value' => 10, 'minimumOrderGross' => null, 'startsInDays' => 0, 'endsInDays' => null];

        yield 'empty list' => [[], 'at least one'];
        yield 'bad code format' => [[[...$percent, 'code' => 'ZŁY KOD']], 'ZŁY KOD'];
        yield 'code too short' => [[[...$percent, 'code' => 'AB']], 'AB'];
        yield 'percent over the maximum' => [[[...$percent, 'value' => 95]], 'WITAJ10'];
        yield 'amount without a minimum' => [[[...$percent, 'code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5000]], 'MINUS50'];
        yield 'amount with a minimum not above it' => [[[...$percent, 'code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5000, 'minimumOrderGross' => 5000]], 'MINUS50'];
        yield 'minimum of zero' => [[[...$percent, 'minimumOrderGross' => 0]], 'WITAJ10'];
        yield 'end not after start' => [[[...$percent, 'startsInDays' => 5, 'endsInDays' => 5]], 'WITAJ10'];
        yield 'end before start' => [[[...$percent, 'startsInDays' => 0, 'endsInDays' => -1]], 'WITAJ10'];
        yield 'duplicate code in another case' => [[$percent, [...$percent, 'code' => 'witaj10']], 'twice'];
        yield 'start absurdly far' => [[[...$percent, 'startsInDays' => 100000]], 'startsInDays'];
    }
}
