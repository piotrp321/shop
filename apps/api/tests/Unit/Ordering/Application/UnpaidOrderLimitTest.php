<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\UnpaidOrderLimit;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\TooManyUnpaidOrders;
use App\Ordering\Domain\UnpaidOrderIdentity;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderClaims;
use App\Tests\Unit\Ordering\Journal;
use PHPUnit\Framework\TestCase;

/**
 * The limit of unpaid orders (ABUSE-LIMITS, L6, D6, D8) with an in-memory port that counts the claims: at most
 * PER_ADDRESS from one address and PER_IDENTITY of one email of a guest or one account, counted under the locks of the
 * address and then of the identity, only claims not yet expired.
 */
final class UnpaidOrderLimitTest extends TestCase
{
    private const int PER_ADDRESS = 5;
    private const int PER_IDENTITY = 3;
    private const string ADDRESS = '203.0.113.7';
    private const string MESSAGE = 'Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.';

    private Journal $journal;
    private InMemoryUnpaidOrderClaims $claims;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->journal = new Journal();
        $this->claims = new InMemoryUnpaidOrderClaims($this->journal);
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-19 12:00:00+00:00'));
    }

    public function testAnAddressBelowItsLimitHasRoom(): void
    {
        $this->claims->existing(self::PER_ADDRESS - 1, self::ADDRESS, 'email:other@example.com', $this->later());

        $this->limit()->ensureRoomFor(self::ADDRESS, self::jan());

        self::assertSame(['lock-unpaid', 'count-address', 'count-identity'], $this->journal->calls, 'counted only under the locks');
    }

    public function testAnAddressAtItsLimitIsRefused(): void
    {
        $this->claims->existing(self::PER_ADDRESS, self::ADDRESS, 'email:other@example.com', $this->later());

        $this->assertRefused(self::ADDRESS, self::jan());
    }

    public function testAnotherAddressIsNotCountedToTheAddress(): void
    {
        $this->claims->existing(self::PER_ADDRESS, '198.51.100.1', 'email:other@example.com', $this->later());

        $this->limit()->ensureRoomFor(self::ADDRESS, self::jan());

        $this->addToAssertionCount(1);
    }

    public function testAnEmailAtItsLimitIsRefusedFromAnyAddress(): void
    {
        $this->claims->existing(self::PER_IDENTITY, '198.51.100.1', self::jan()->key(), $this->later());

        $this->assertRefused(self::ADDRESS, self::jan());
        $this->assertRefused(self::ADDRESS, UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString(' JAN+sklep@Example.com')));
    }

    public function testAnEmailBelowItsLimitHasRoom(): void
    {
        $this->claims->existing(self::PER_IDENTITY - 1, '198.51.100.1', self::jan()->key(), $this->later());

        $this->limit()->ensureRoomFor(self::ADDRESS, self::jan());

        $this->addToAssertionCount(1);
    }

    public function testAnAccountAtItsLimitIsRefusedAndTheGuestOrdersWithItsEmailDoNotCountToIt(): void
    {
        $customer = UnpaidOrderIdentity::of(OrderOwner::customer('kc-sub-1'), EmailAddress::fromString('jan@example.com'));
        $this->claims->existing(self::PER_IDENTITY, '198.51.100.1', self::jan()->key(), $this->later());

        $this->limit()->ensureRoomFor(self::ADDRESS, $customer);

        $this->claims->existing(self::PER_IDENTITY, '198.51.100.2', $customer->key(), $this->later());
        $this->assertRefused(self::ADDRESS, $customer);
    }

    public function testExpiredClaimsDoNotCountAndTheClockIsReadAfterTheLocks(): void
    {
        $this->claims->existing(10, self::ADDRESS, self::jan()->key(), $this->clock->now);
        $this->claims->existing(10, self::ADDRESS, self::jan()->key(), $this->clock->now->modify('-1 minute'));

        $this->limit()->ensureRoomFor(self::ADDRESS, self::jan());

        self::assertEquals([$this->clock->now, $this->clock->now], $this->claims->countedAt);
    }

    public function testTheLocksAreTheAddressAndThenTheIdentity(): void
    {
        $this->limit()->ensureRoomFor(self::ADDRESS, self::jan());

        self::assertSame([[self::ADDRESS, 'email:jan@example.com']], $this->claims->locked);
    }

    public function testTheRefusalIsTheSameForTheAddressTheEmailAndTheAccount(): void
    {
        $customer = UnpaidOrderIdentity::of(OrderOwner::customer('kc-sub-1'), EmailAddress::fromString('jan@example.com'));
        $this->claims->existing(self::PER_ADDRESS, '198.51.100.9', 'email:other@example.com', $this->later());
        $this->claims->existing(self::PER_IDENTITY, '198.51.100.1', self::jan()->key(), $this->later());
        $this->claims->existing(self::PER_IDENTITY, '198.51.100.2', $customer->key(), $this->later());

        $refusals = [
            $this->refusal('198.51.100.9', UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString('ewa@example.com'))),
            $this->refusal(self::ADDRESS, self::jan()),
            $this->refusal(self::ADDRESS, $customer),
        ];

        foreach ($refusals as $refusal) {
            self::assertSame([TooManyUnpaidOrders::TYPE, self::MESSAGE], [$refusal->problemType(), $refusal->getMessage()]);
        }
    }

    public function testAClaimIsWrittenWithTheExpiryOfTheOrder(): void
    {
        $id = OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000c1');

        $this->limit()->claim($id, self::ADDRESS, self::jan(), $this->later());

        self::assertEquals(['0192f0c4-7c1a-7c4e-9a6e-0000000000c1' => ['address' => self::ADDRESS, 'identity' => 'email:jan@example.com', 'expiresAt' => $this->later()]], $this->claims->claims);
    }

    private function assertRefused(string $address, UnpaidOrderIdentity $identity): void
    {
        $refusal = $this->refusal($address, $identity);
        self::assertSame(self::MESSAGE, $refusal->getMessage());
    }

    private function refusal(string $address, UnpaidOrderIdentity $identity): TooManyUnpaidOrders
    {
        try {
            $this->limit()->ensureRoomFor($address, $identity);
        } catch (TooManyUnpaidOrders $refused) {
            return $refused;
        }
        self::fail('Expected TooManyUnpaidOrders.');
    }

    private function limit(): UnpaidOrderLimit
    {
        return new UnpaidOrderLimit($this->claims, $this->claims, $this->clock, self::PER_ADDRESS, self::PER_IDENTITY);
    }

    private function later(): \DateTimeImmutable
    {
        return $this->clock->now->modify('+35 minutes');
    }

    private static function jan(): UnpaidOrderIdentity
    {
        return UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString('jan@example.com'));
    }
}
