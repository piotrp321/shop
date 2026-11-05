<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\GuestCartMerged;
use App\Cart\Domain\MergeOutcome;
use App\Cart\Domain\MergeOutcomeResult;
use App\Cart\Domain\ProductOffer;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use PHPUnit\Framework\TestCase;

/**
 * Cart::mergeFrom() and Cart::markMerged() (spec: "Scalanie (mergeFrom)").
 */
final class CartMergeTest extends TestCase
{
    private const string GUEST = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string CUSTOMER = '1d2c3b4a-5e6f-4a70-8b9c-0d1e2f3a4b5c';
    private const string V1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string V2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';
    private const string V3 = '0192f0c4-7c1a-7c4e-9a6e-000000000003';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-03 12:00:00+00:00');
    }

    public function testTheGuestLinesAreAddedWithTheirAddedAtAndTheGuestCartIsMerged(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 1, '-3 days')]);
        $guest = $this->guestCart([$this->line(self::V2, 2, '-2 days'), $this->line(self::V3, 1, '-1 day')]);

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertSame(MergeOutcomeResult::Merged, $outcome->result);
        self::assertFalse($outcome->truncated);
        self::assertSame([[self::V1, 1], [self::V2, 2], [self::V3, 1]], self::lines($customer));
        self::assertEquals([$this->at('-3 days'), $this->at('-2 days'), $this->at('-1 day')], self::addedAt($customer));
        self::assertEquals($this->now, $customer->updatedAt());
        self::assertEquals($this->now->modify('+180 days'), $customer->expiresAt());

        self::assertSame(CartStatus::Merged, $guest->status());
        self::assertSame([], $guest->lines());
        self::assertTrue($customer->id()->equals($guest->mergedInto() ?? CartId::generate()));
        self::assertEquals($this->now, $guest->mergedAt());
    }

    public function testTheQuantitiesOfAProductInBothCartsAreSummedOnTheCustomerLine(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 3, '-3 days')]);
        $guest = $this->guestCart([$this->line(self::V1, 4, '-1 day')]);

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertSame([[self::V1, 7]], self::lines($customer));
        self::assertEquals([$this->at('-3 days')], self::addedAt($customer), 'the customer line keeps its place');
    }

    public function testASumUpTo99IsNotTruncated(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 60, '-3 days')]);

        $outcome = $customer->mergeFrom($this->guestCart([$this->line(self::V1, 39, '-1 day')]), $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertSame([[self::V1, 99]], self::lines($customer));
    }

    public function testASumAbove99IsCutTo99AndTruncated(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 60, '-3 days'), $this->line(self::V2, 99, '-3 days')]);
        $guest = $this->guestCart([$this->line(self::V1, 40, '-2 days'), $this->line(self::V2, 1, '-2 days'), $this->line(self::V3, 5, '-1 day')]);

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::merged(true), $outcome);
        self::assertSame([[self::V1, 99], [self::V2, 99], [self::V3, 5]], self::lines($customer));
        self::assertSame(CartStatus::Merged, $guest->status());
        self::assertSame([], $guest->lines());
    }

    public function testNewLinesAreAddedInTheOrderOfAddedAtUpTo50AndTheRestIsSkipped(): void
    {
        $existing = [];
        for ($i = 1; $i <= 48; ++$i) {
            $existing[] = new CartLine(self::numbered($i), Quantity::of(1), $this->at('-10 days'));
        }
        $customer = $this->customerCart('+100 days', $existing);
        $guest = $this->guestCart([
            new CartLine(self::numbered(101), Quantity::of(2), $this->at('-4 days')),
            new CartLine(self::numbered(1), Quantity::of(3), $this->at('-3 days')),
            new CartLine(self::numbered(102), Quantity::of(1), $this->at('-2 days')),
            new CartLine(self::numbered(103), Quantity::of(1), $this->at('-1 day')),
        ]);

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::merged(true), $outcome);
        self::assertCount(50, $customer->lines());
        $quantities = array_column(self::lines($customer), 1, 0);
        self::assertSame(2, $quantities[self::numbered(101)->toString()] ?? null);
        self::assertSame(4, $quantities[self::numbered(1)->toString()] ?? null, 'an existing product is summed in a full cart');
        self::assertSame(1, $quantities[self::numbered(102)->toString()] ?? null);
        self::assertArrayNotHasKey(self::numbered(103)->toString(), $quantities, 'the 51st product is skipped');
        self::assertSame([], $guest->lines(), 'a skipped line is gone with the guest cart');
    }

    public function testAFullCustomerCartStillSumsTheQuantitiesWithoutTruncation(): void
    {
        $existing = [];
        for ($i = 1; $i <= 50; ++$i) {
            $existing[] = new CartLine(self::numbered($i), Quantity::of(1), $this->at('-10 days'));
        }
        $customer = $this->customerCart('+100 days', $existing);

        $outcome = $customer->mergeFrom($this->guestCart([new CartLine(self::numbered(7), Quantity::of(5), $this->at('-1 day'))]), $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertCount(50, $customer->lines());
        self::assertSame(6, array_column(self::lines($customer), 1, 0)[self::numbered(7)->toString()] ?? null);
    }

    public function testTheLinesFollowAddedAtAlsoWhenAGuestLineIsOlderThanTheCustomerLines(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 1, '-1 day')]);

        $customer->mergeFrom($this->guestCart([$this->line(self::V2, 1, '-5 days')]), $this->now);

        self::assertSame([[self::V2, 1], [self::V1, 1]], self::lines($customer));
        self::assertSame([self::V2, self::V1], array_map(static fn (VariantId $id): string => $id->toString(), $customer->variantIds()));
    }

    public function testMergeNeitherChecksThePriceNorTheAvailability(): void
    {
        // There is no offer to give: a merge never asks for one.
        $customer = $this->customerCart('+100 days', []);

        $outcome = $customer->mergeFrom($this->guestCart([$this->line(self::V1, 99, '-1 day')]), $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertSame([[self::V1, 99]], self::lines($customer));
    }

    public function testAnEmptyGuestCartIsMarkedMergedWithoutTruncation(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 2, '-3 days')]);
        $guest = $this->guestCart([]);

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertSame(CartStatus::Merged, $guest->status());
        self::assertSame([[self::V1, 2]], self::lines($customer));
        self::assertEquals($this->now->modify('+180 days'), $customer->expiresAt());
    }

    public function testAnExpiredCustomerCartKeepsOnlyTheGuestLinesAndIsRenewed(): void
    {
        $customer = $this->customerCart('-1 second', [$this->line(self::V1, 5, '-200 days'), $this->line(self::V2, 1, '-190 days')]);
        $guest = $this->guestCart([$this->line(self::V1, 2, '-2 days'), $this->line(self::V3, 1, '-1 day')]);

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::merged(false), $outcome);
        self::assertSame([[self::V1, 2], [self::V3, 1]], self::lines($customer));
        self::assertEquals([$this->at('-2 days'), $this->at('-1 day')], self::addedAt($customer), 'the lines are the guest lines');
        self::assertEquals($this->now->modify('+180 days'), $customer->expiresAt());
        self::assertFalse($customer->isExpired($this->now));
    }

    public function testAMergedGuestCartIsAlreadyMergedAndNothingChanges(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 2, '-3 days')]);
        $into = CartId::generate();
        $mergedAt = $this->at('-1 hour');
        $guest = Cart::reconstitute(CartId::fromString(self::GUEST), CartOwner::guest(), CartStatus::Merged, $into, $mergedAt, [], $this->at('-5 days'), $mergedAt, $this->at('+20 days'));

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::alreadyMerged(), $outcome);
        self::assertSame(MergeOutcomeResult::AlreadyMerged, $outcome->result);
        self::assertFalse($outcome->truncated);
        self::assertSame([[self::V1, 2]], self::lines($customer));
        self::assertEquals($this->at('+100 days'), $customer->expiresAt());
        self::assertSame($into, $guest->mergedInto());
        self::assertSame($mergedAt, $guest->mergedAt());
    }

    public function testAnExpiredGuestCartHasNothingToMergeAndNothingChanges(): void
    {
        $customer = $this->customerCart('-1 second', [$this->line(self::V1, 2, '-200 days')]);
        $guest = Cart::reconstitute(CartId::fromString(self::GUEST), CartOwner::guest(), CartStatus::Open, null, null, [$this->line(self::V2, 1, '-40 days')], $this->at('-40 days'), $this->at('-31 days'), $this->at('-1 second'));

        $outcome = $customer->mergeFrom($guest, $this->now);

        self::assertEquals(MergeOutcome::nothingToMerge(), $outcome);
        self::assertSame([[self::V1, 2]], self::lines($customer), 'not even the expired customer cart is cleared');
        self::assertSame(CartStatus::Open, $guest->status());
        self::assertSame([[self::V2, 1]], self::lines($guest));
    }

    public function testACustomerCartHasNothingToMerge(): void
    {
        $customer = $this->customerCart('+100 days', [$this->line(self::V1, 2, '-3 days')]);
        $other = Cart::reconstitute(CartId::generate(), CartOwner::customer('kc-sub-2'), CartStatus::Open, null, null, [$this->line(self::V2, 1, '-1 day')], $this->at('-5 days'), $this->at('-1 day'), $this->at('+100 days'));

        self::assertEquals(MergeOutcome::nothingToMerge(), $customer->mergeFrom($other, $this->now));
        self::assertEquals(MergeOutcome::nothingToMerge(), $customer->mergeFrom($customer, $this->now));
        self::assertSame([[self::V1, 2]], self::lines($customer));
        self::assertSame([[self::V2, 1]], self::lines($other));
    }

    public function testOutcomeWithoutMergeTellsWhetherACartCanBeMerged(): void
    {
        $open = $this->guestCart([]);
        $expiringNow = Cart::reconstitute(CartId::generate(), CartOwner::guest(), CartStatus::Open, null, null, [], $this->at('-30 days'), $this->at('-30 days'), $this->now);
        $expired = Cart::reconstitute(CartId::generate(), CartOwner::guest(), CartStatus::Open, null, null, [], $this->at('-40 days'), $this->at('-31 days'), $this->at('-1 second'));
        $merged = Cart::reconstitute(CartId::generate(), CartOwner::guest(), CartStatus::Merged, CartId::generate(), $this->at('-1 day'), [], $this->at('-5 days'), $this->at('-1 day'), $this->at('+20 days'));
        $mergedAndExpired = Cart::reconstitute(CartId::generate(), CartOwner::guest(), CartStatus::Merged, CartId::generate(), $this->at('-40 days'), [], $this->at('-45 days'), $this->at('-40 days'), $this->at('-10 days'));

        self::assertNull($open->outcomeWithoutMerge($this->now));
        self::assertNull($expiringNow->outcomeWithoutMerge($this->now));
        self::assertEquals(MergeOutcome::nothingToMerge(), $expired->outcomeWithoutMerge($this->now));
        self::assertEquals(MergeOutcome::alreadyMerged(), $merged->outcomeWithoutMerge($this->now));
        self::assertEquals(MergeOutcome::alreadyMerged(), $mergedAndExpired->outcomeWithoutMerge($this->now));
        self::assertEquals(MergeOutcome::nothingToMerge(), $this->customerCart('+100 days', [])->outcomeWithoutMerge($this->now));
    }

    public function testOnlyACustomerCartTakesAMerge(): void
    {
        $guest = $this->guestCart([$this->line(self::V1, 1, '-1 day')]);
        $other = Cart::createForGuest(CartId::generate(), $this->now);

        $this->expectException(\LogicException::class);
        $other->mergeFrom($guest, $this->now);
    }

    public function testAMergedGuestCartRefusesEveryChange(): void
    {
        $guest = $this->guestCart([$this->line(self::V1, 1, '-1 day')]);
        $this->customerCart('+100 days', [])->mergeFrom($guest, $this->now);

        $this->expectException(GuestCartMerged::class);
        $guest->add(VariantId::fromString(self::V2), Quantity::of(1), new ProductOffer(true, 10), $this->now);
    }

    public function testMarkMergedEmptiesTheGuestCartAndRecordsTheCustomerCart(): void
    {
        $guest = $this->guestCart([$this->line(self::V1, 1, '-1 day')]);
        $expiresAt = $guest->expiresAt();
        $into = CartId::fromString(self::CUSTOMER);

        $guest->markMerged($into, $this->now);

        self::assertSame(CartStatus::Merged, $guest->status());
        self::assertSame([], $guest->lines());
        self::assertSame($into, $guest->mergedInto());
        self::assertEquals($this->now, $guest->mergedAt());
        self::assertEquals($this->now, $guest->updatedAt());
        self::assertEquals($expiresAt, $guest->expiresAt(), 'a merged cart only waits for its purge');
    }

    public function testMarkMergedOfAMergedGuestCartIsALogicError(): void
    {
        $guest = $this->guestCart([]);
        $guest->markMerged(CartId::fromString(self::CUSTOMER), $this->now);

        $this->expectException(\LogicException::class);
        $guest->markMerged(CartId::generate(), $this->now);
    }

    public function testMarkMergedOfACustomerCartIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->customerCart('+100 days', [])->markMerged(CartId::generate(), $this->now);
    }

    /**
     * @param list<CartLine> $lines
     */
    private function customerCart(string $expiresIn, array $lines): Cart
    {
        return Cart::reconstitute(CartId::fromString(self::CUSTOMER), CartOwner::customer('kc-sub-1'), CartStatus::Open, null, null, $lines, $this->at('-300 days'), $this->at('-10 days'), $this->at($expiresIn));
    }

    /**
     * @param list<CartLine> $lines
     */
    private function guestCart(array $lines): Cart
    {
        return Cart::reconstitute(CartId::fromString(self::GUEST), CartOwner::guest(), CartStatus::Open, null, null, $lines, $this->at('-5 days'), $this->at('-1 day'), $this->at('+29 days'));
    }

    private function line(string $variantId, int $quantity, string $addedAgo): CartLine
    {
        return new CartLine(VariantId::fromString($variantId), Quantity::of($quantity), $this->at($addedAgo));
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->now->modify($modifier);
    }

    private static function numbered(int $number): VariantId
    {
        return VariantId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $number));
    }

    /**
     * @return list<array{string, int}>
     */
    private static function lines(Cart $cart): array
    {
        return array_map(static fn (CartLine $line): array => [$line->variantId()->toString(), $line->quantity()->value()], $cart->lines());
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private static function addedAt(Cart $cart): array
    {
        return array_map(static fn (CartLine $line): \DateTimeImmutable => $line->addedAt(), $cart->lines());
    }
}
