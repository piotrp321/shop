<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\CartContentChanged;
use App\Ordering\Domain\ExpectedLines;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lines the checkout showed (spec cart-order-consistency, Z1): unique variants, 1 to 99 units, 1 to 50 lines,
 * compared with the lines of the cart as sets of pairs of variant and quantity.
 */
final class ExpectedLinesTest extends TestCase
{
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string CAP = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string MAP = '0192f0c4-7c1a-7c4e-9a6e-00000000000c';
    private const string INVALID = 'Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.';

    public function testTheSameLinesInAnotherOrderAndLetterCaseMatch(): void
    {
        $expected = ExpectedLines::of([self::line(strtoupper(self::CAP), 1), self::line(self::SHOES, 2)]);

        self::assertTrue($expected->matches([[VariantId::fromString(self::SHOES), 2], [VariantId::fromString(self::CAP), 1]]));
        self::assertTrue($expected->matches([[VariantId::fromString(self::CAP), 1], [VariantId::fromString(self::SHOES), 2]]));
    }

    public function testAnotherQuantityAMissingLineOrAnExtraLineDoNotMatch(): void
    {
        $expected = ExpectedLines::of([self::line(self::SHOES, 2), self::line(self::CAP, 1)]);

        self::assertFalse($expected->matches([[VariantId::fromString(self::SHOES), 1], [VariantId::fromString(self::CAP), 1]]), 'another quantity');
        self::assertFalse($expected->matches([[VariantId::fromString(self::SHOES), 1], [VariantId::fromString(self::CAP), 2]]), 'the quantities swapped');
        self::assertFalse($expected->matches([[VariantId::fromString(self::SHOES), 2]]), 'a line missing');
        self::assertFalse($expected->matches([[VariantId::fromString(self::SHOES), 2], [VariantId::fromString(self::CAP), 1], [VariantId::fromString(self::MAP), 1]]), 'an extra line');
        self::assertFalse($expected->matches([[VariantId::fromString(self::SHOES), 2], [VariantId::fromString(self::MAP), 1]]), 'another variant');
        self::assertFalse($expected->matches([]), 'no line');
    }

    public function testACartWithAVariantTwiceDoesNotMatch(): void
    {
        $expected = ExpectedLines::of([self::line(self::SHOES, 2)]);

        self::assertFalse($expected->matches([[VariantId::fromString(self::SHOES), 2], [VariantId::fromString(self::SHOES), 2]]));
    }

    public function testTheCanonicalFormIsSortedByTheVariantInLowerCase(): void
    {
        $canonical = ExpectedLines::of([self::line(strtoupper(self::MAP), 3), self::line(self::SHOES, 2), self::line(self::CAP, 1)])->canonical();

        // …00a, …00b, …00c
        self::assertSame(self::SHOES.'*2,'.self::CAP.'*1,'.self::MAP.'*3', $canonical);
        self::assertSame($canonical, ExpectedLines::of([self::line(self::CAP, 1), self::line(self::SHOES, 2), self::line(self::MAP, 3)])->canonical());
    }

    public function testTheBoundsAreAccepted(): void
    {
        self::assertTrue(ExpectedLines::of([self::line(self::SHOES, 1)])->matches([[VariantId::fromString(self::SHOES), 1]]));
        self::assertTrue(ExpectedLines::of([self::line(self::SHOES, 99)])->matches([[VariantId::fromString(self::SHOES), 99]]));
        $fifty = self::lines(50);
        self::assertTrue(ExpectedLines::of($fifty)->matches(array_map(static fn (array $line): array => [VariantId::fromString($line['variantId']), $line['quantity']], $fifty)));
    }

    /**
     * @return iterable<string, array{list<array{variantId: string, quantity: int}>}>
     */
    public static function invalidLists(): iterable
    {
        yield 'no line' => [[]];
        yield '51 lines' => [self::lines(51)];
        yield '0 units' => [[self::line(self::SHOES, 0)]];
        yield 'a negative quantity' => [[self::line(self::SHOES, -1)]];
        yield '100 units' => [[self::line(self::SHOES, 100)]];
        yield 'a variant that is no UUID' => [[self::line('buty', 1)]];
        yield 'a variant in braces' => [[self::line('{'.self::SHOES.'}', 1)]];
        yield 'a variant with a trailing line break' => [[self::line(self::SHOES."\n", 1)]];
        yield 'the same variant twice' => [[self::line(self::SHOES, 1), self::line(self::SHOES, 2)]];
        yield 'the same variant twice in another letter case' => [[self::line(self::SHOES, 1), self::line(self::CAP, 1), self::line(strtoupper(self::SHOES), 1)]];
    }

    /**
     * @param list<array{variantId: string, quantity: int}> $lines
     */
    #[DataProvider('invalidLists')]
    public function testAnInvalidListIsRefused(array $lines): void
    {
        $this->expectException(InvalidOrderInput::class);
        $this->expectExceptionMessage(self::INVALID);

        ExpectedLines::of($lines);
    }

    public function testTheConflictOfAnotherContentHasItsTypeAndPolishDetail(): void
    {
        $changed = new CartContentChanged();

        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/cart-changed', $changed->problemType());
        self::assertSame('Zawartość koszyka się zmieniła. Sprawdź podsumowanie.', $changed->getMessage());
    }

    /**
     * @return array{variantId: string, quantity: int}
     */
    private static function line(string $variantId, int $quantity): array
    {
        return ['variantId' => $variantId, 'quantity' => $quantity];
    }

    /**
     * @return list<array{variantId: string, quantity: int}>
     */
    private static function lines(int $count): array
    {
        $lines = [];
        for ($i = 1; $i <= $count; ++$i) {
            $lines[] = self::line(\sprintf('0192f0c4-7c1a-7c4e-9a6e-1%011d', $i), 1);
        }

        return $lines;
    }
}
