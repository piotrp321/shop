<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionalPrice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotionalPriceTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function prices(): iterable
    {
        yield '1 % of 100.00 zł' => [10000, 1, 9900];
        yield '1 % rounds the discount down' => [199, 1, 198];
        yield '1 % of less than 1 zł is no discount' => [99, 1, 99];
        yield '90 % of 100.00 zł' => [10000, 90, 1000];
        yield '90 % rounds the discount down, the price up' => [1999, 90, 200];
        yield '20 % of 59.99 zł' => [5999, 20, 4800];
        yield '1 grosz at 90 % stays 1 grosz' => [GrossPrice::MIN, 90, 1];
        yield '1 grosz at 1 % stays 1 grosz' => [GrossPrice::MIN, 1, 1];
        yield '2 grosze at 90 %' => [2, 90, 1];
        yield 'the maximum at 1 %' => [GrossPrice::MAX, 1, 99_000_000];
        yield 'the maximum at 90 %' => [GrossPrice::MAX, 90, 10_000_000];
    }

    /**
     * catalog − floor(catalog × percent / 100), in integers.
     */
    #[DataProvider('prices')]
    public function testThePromotionalPriceIsTheCatalogPriceLessTheDiscountRoundedDown(int $catalog, int $percent, int $promotional): void
    {
        $price = PromotionalPrice::apply(GrossPrice::pln($catalog), PercentOff::of($percent));

        self::assertSame($promotional, $price->amount());
    }

    public function testEveryPriceAndPercentGivesAtLeastOneGroszAndNeverMoreThanTheCatalogPrice(): void
    {
        foreach ([1, 2, 3, 7, 99, 100, 101, 12345, GrossPrice::MAX] as $catalog) {
            for ($percent = PercentOff::MIN; $percent <= PercentOff::MAX; ++$percent) {
                $promotional = PromotionalPrice::apply(GrossPrice::pln($catalog), PercentOff::of($percent))->amount();

                self::assertSame($catalog - intdiv($catalog * $percent, 100), $promotional);
                self::assertGreaterThanOrEqual(1, $promotional);
                self::assertLessThanOrEqual($catalog, $promotional);
            }
        }
    }
}
