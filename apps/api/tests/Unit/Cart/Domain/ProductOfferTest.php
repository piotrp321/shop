<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\ProductOffer;
use App\Cart\Domain\Purchasability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductOfferTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, int, int, Purchasability}>
     */
    public static function cases(): iterable
    {
        yield 'no price and nothing available: the price comes first' => [false, 0, 1, Purchasability::NoPrice];
        yield 'no price with units available' => [false, 10, 1, Purchasability::NoPrice];
        yield 'no price, quantity within the units' => [false, 10, 10, Purchasability::NoPrice];
        yield 'no price, quantity above the units' => [false, 3, 4, Purchasability::NoPrice];
        yield 'price but nothing available' => [true, 0, 1, Purchasability::OutOfStock];
        yield 'nothing available, any quantity' => [true, 0, 99, Purchasability::OutOfStock];
        yield 'quantity one below the units' => [true, 3, 2, Purchasability::Purchasable];
        yield 'quantity equal to the units' => [true, 3, 3, Purchasability::Purchasable];
        yield 'quantity one above the units' => [true, 3, 4, Purchasability::InsufficientStock];
        yield 'one unit available, one requested' => [true, 1, 1, Purchasability::Purchasable];
        yield 'one unit available, two requested' => [true, 1, 2, Purchasability::InsufficientStock];
        yield 'many units available' => [true, 500, 99, Purchasability::Purchasable];
    }

    #[DataProvider('cases')]
    public function testPurchasabilityFollowsTheOrderOfTheRule(bool $hasCurrentPrice, int $available, int $quantity, Purchasability $expected): void
    {
        self::assertSame($expected, (new ProductOffer($hasCurrentPrice, $available))->purchasability($quantity));
    }
}
