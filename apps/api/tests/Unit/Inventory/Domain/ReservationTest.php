<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\Reservation\InvalidReservation;
use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Reservation\ReservationLine;
use App\Inventory\Domain\Reservation\ReservationStateConflict;
use App\Inventory\Domain\Reservation\ReservationStatus;
use App\Inventory\Domain\Stock\ProductId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservationTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string C = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';

    public function testPlacedReservationIsActiveWithLinesOrderedByProduct(): void
    {
        $reservation = Reservation::place(ReservationKey::fromString('order-1'), [$this->line(self::C, 1), $this->line(self::A, 2), $this->line(self::B, 3)]);

        self::assertSame('order-1', $reservation->key()->toString());
        self::assertSame(ReservationStatus::Active, $reservation->status());
        self::assertSame(
            [[self::A, 2], [self::B, 3], [self::C, 1]],
            array_map(static fn (ReservationLine $l): array => [$l->productId()->toString(), $l->quantity()], $reservation->lines()),
        );
    }

    public function testStatusValues(): void
    {
        self::assertSame(
            [ReservationStatus::Active, ReservationStatus::Released, ReservationStatus::Committed],
            array_map(ReservationStatus::from(...), ['active', 'released', 'committed']),
        );
    }

    public function testNoLinesIsInvalid(): void
    {
        $this->expectException(InvalidReservation::class);

        Reservation::place(ReservationKey::fromString('order-1'), []);
    }

    public function testHundredLinesAreValidAndHundredAndOneAreNot(): void
    {
        $lines = [];
        for ($i = 0; $i < 101; ++$i) {
            $lines[] = $this->line(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1);
        }

        self::assertCount(100, Reservation::place(ReservationKey::fromString('order-1'), \array_slice($lines, 0, 100))->lines());

        $this->expectException(InvalidReservation::class);
        Reservation::place(ReservationKey::fromString('order-1'), $lines);
    }

    public function testARepeatedProductIsInvalid(): void
    {
        $this->expectException(InvalidReservation::class);

        Reservation::place(ReservationKey::fromString('order-1'), [$this->line(self::A, 1), $this->line(self::B, 1), $this->line(self::A, 2)]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function quantitiesOutOfRange(): iterable
    {
        yield '0' => [0];
        yield '-1' => [-1];
        yield '1001' => [1001];
    }

    #[DataProvider('quantitiesOutOfRange')]
    public function testLineQuantityOutOfRangeIsInvalid(int $quantity): void
    {
        $this->expectException(InvalidReservation::class);

        ReservationLine::of(ProductId::fromString(self::A), $quantity);
    }

    public function testLineQuantityLimitsAreValid(): void
    {
        self::assertSame(1, ReservationLine::of(ProductId::fromString(self::A), 1)->quantity());
        self::assertSame(1000, ReservationLine::of(ProductId::fromString(self::A), 1000)->quantity());
    }

    public function testReleaseOfAnActiveReservation(): void
    {
        $reservation = $this->active();

        self::assertTrue($reservation->release());
        self::assertSame(ReservationStatus::Released, $reservation->status());
        self::assertFalse($reservation->release());
        self::assertSame(ReservationStatus::Released, $reservation->status());
    }

    public function testCommitOfAnActiveReservation(): void
    {
        $reservation = $this->active();

        self::assertTrue($reservation->commit());
        self::assertSame(ReservationStatus::Committed, $reservation->status());
        self::assertFalse($reservation->commit());
        self::assertSame(ReservationStatus::Committed, $reservation->status());
    }

    public function testCommitOfAReleasedReservationIsAConflict(): void
    {
        $reservation = $this->stored(ReservationStatus::Released);

        try {
            $reservation->commit();
            self::fail('Expected ReservationStateConflict.');
        } catch (ReservationStateConflict) {
        }
        self::assertSame(ReservationStatus::Released, $reservation->status());
    }

    public function testReleaseOfACommittedReservationIsAConflict(): void
    {
        $reservation = $this->stored(ReservationStatus::Committed);

        try {
            $reservation->release();
            self::fail('Expected ReservationStateConflict.');
        } catch (ReservationStateConflict) {
        }
        self::assertSame(ReservationStatus::Committed, $reservation->status());
    }

    public function testHasLinesComparesTheSetOfProductsAndQuantities(): void
    {
        $reservation = Reservation::place(ReservationKey::fromString('order-1'), [$this->line(self::A, 2), $this->line(self::B, 1)]);

        self::assertTrue($reservation->hasLines([$this->line(self::B, 1), $this->line(self::A, 2)]));
        self::assertFalse($reservation->hasLines([$this->line(self::A, 2), $this->line(self::B, 2)]));
        self::assertFalse($reservation->hasLines([$this->line(self::A, 2)]));
        self::assertFalse($reservation->hasLines([$this->line(self::A, 2), $this->line(self::B, 1), $this->line(self::C, 1)]));
        self::assertFalse($reservation->hasLines([$this->line(self::A, 2), $this->line(self::C, 1)]));
    }

    public function testReconstituteKeepsTheStatus(): void
    {
        $reservation = $this->stored(ReservationStatus::Committed);

        self::assertSame(ReservationStatus::Committed, $reservation->status());
        self::assertSame('order-1', $reservation->key()->toString());
        self::assertCount(1, $reservation->lines());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validKeys(): iterable
    {
        yield 'one character' => ['a'];
        yield '100 characters' => [str_repeat('k', 100)];
        yield 'all allowed characters' => ['Order_2026.11-02:AZaz09'];
        yield 'uuid' => ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'];
    }

    #[DataProvider('validKeys')]
    public function testValidKeys(string $key): void
    {
        self::assertSame($key, ReservationKey::fromString($key)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield '101 characters' => [str_repeat('k', 101)];
        yield 'space' => ['order 1'];
        yield 'slash' => ['order/1'];
        yield 'non-ASCII letter' => ['zamówienie'];
        yield 'trailing newline' => ["order-1\n"];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeys(string $key): void
    {
        $this->expectException(InvalidReservation::class);

        ReservationKey::fromString($key);
    }

    public function testKeysAreEqualByValue(): void
    {
        self::assertTrue(ReservationKey::fromString('order-1')->equals(ReservationKey::fromString('order-1')));
        self::assertFalse(ReservationKey::fromString('order-1')->equals(ReservationKey::fromString('Order-1')));
    }

    private function active(): Reservation
    {
        return Reservation::place(ReservationKey::fromString('order-1'), [$this->line(self::A, 1)]);
    }

    private function stored(ReservationStatus $status): Reservation
    {
        return Reservation::reconstitute(ReservationKey::fromString('order-1'), [$this->line(self::A, 1)], $status);
    }

    private function line(string $productId, int $quantity): ReservationLine
    {
        return ReservationLine::of(ProductId::fromString($productId), $quantity);
    }
}
