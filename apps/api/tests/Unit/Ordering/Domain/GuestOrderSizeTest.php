<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\GuestOrderSize;
use App\Ordering\Domain\GuestOrderTooLarge;
use PHPUnit\Framework\TestCase;

final class GuestOrderSizeTest extends TestCase
{
    public function testTenLinesOfTenUnitsEachAreAccepted(): void
    {
        GuestOrderSize::ensureWithin(array_fill(0, 10, 10));

        $this->addToAssertionCount(1);
    }

    public function testASingleSmallLineIsAccepted(): void
    {
        GuestOrderSize::ensureWithin([1]);

        $this->addToAssertionCount(1);
    }

    public function testElevenLinesAreRefused(): void
    {
        $this->assertRefused(array_fill(0, 11, 1));
    }

    public function testElevenUnitsOfOneLineAreRefused(): void
    {
        $this->assertRefused([1, 11, 1]);
    }

    public function testTheMaximumsOfACartAreRefused(): void
    {
        $this->assertRefused([99]);
        $this->assertRefused(array_fill(0, 50, 99));
    }

    /**
     * @param list<int> $quantities
     */
    private function assertRefused(array $quantities): void
    {
        try {
            GuestOrderSize::ensureWithin($quantities);
            self::fail('Expected GuestOrderTooLarge.');
        } catch (GuestOrderTooLarge $tooLarge) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/guest-order-too-large', $tooLarge->problemType());
            self::assertSame('Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku.', $tooLarge->getMessage());
        }
    }
}
