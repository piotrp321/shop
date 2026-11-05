<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000aa';
    private const string PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-000000000042';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-05 12:00:00+00:00');
    }

    public function testPlaceCreatesANewOrderAndRecordsOrderPlaced(): void
    {
        $lines = [self::line(1, 2, 12_300), self::line(2, 1, 2_000)];

        $order = $this->place($lines);

        self::assertSame(self::ORDER, $order->id()->toString());
        self::assertSame('R-100001', $order->number()->toString());
        self::assertTrue($order->owner()->isGuest());
        self::assertSame('Jan Kowalski', $order->contact()->fullName());
        self::assertSame('Warszawa', $order->shippingAddress()->city());
        self::assertSame($lines, $order->lines());
        self::assertSame(26_600, $order->totals()->gross()->amount());
        self::assertSame('key-1', $order->idempotencyKey()->toString());
        self::assertTrue($order->requestFingerprint()->equals(self::fingerprint()));
        self::assertEquals($this->now, $order->placedAt());
        self::assertNull($order->confirmationSentAt());
        self::assertSame(1, $order->version());

        $events = $order->releaseEvents();
        self::assertEquals([new OrderPlaced(self::ORDER, $this->now)], $events);
        self::assertSame($this->now, $events[0]->occurredAt());
        self::assertSame([], $order->releaseEvents(), 'the events are released once');
    }

    public function testAnOrderHasOneToFiftyLines(): void
    {
        $lines = [];
        for ($i = 1; $i <= 50; ++$i) {
            $lines[] = self::line($i, 1, 100);
        }
        self::assertCount(50, $this->place($lines)->lines());

        foreach ([[], [...$lines, self::line(51, 1, 100)]] as $invalid) {
            try {
                $this->place($invalid);
                self::fail('Expected InvalidOrder for '.\count($invalid).' lines.');
            } catch (InvalidOrder) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAVariantIsInOneLineOnly(): void
    {
        $this->expectException(InvalidOrder::class);

        $this->place([self::line(1, 1, 100), self::line(2, 1, 100), self::line(1, 2, 100)]);
    }

    public function testIsReplayOfTheSameFingerprintOnly(): void
    {
        $order = $this->place([self::line(1, 1, 100)]);
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');

        self::assertTrue($order->isReplayOf(self::fingerprint()));
        self::assertFalse($order->isReplayOf(RequestFingerprint::of('guest:another', self::contact(), $address, 100)));
        self::assertFalse($order->isReplayOf(RequestFingerprint::of('guest:cart', self::contact(), $address, 101)));
    }

    public function testMarkConfirmationSentIsIdempotent(): void
    {
        $order = $this->place([self::line(1, 1, 100)]);
        $order->releaseEvents();
        $first = $this->now->modify('+1 minute');

        $order->markConfirmationSent($first);
        $order->markConfirmationSent($first->modify('+1 minute'));

        self::assertEquals($first, $order->confirmationSentAt());
        self::assertSame([], $order->releaseEvents());
    }

    public function testReconstituteKeepsTheStoredStateWithoutEvents(): void
    {
        $sentAt = $this->now->modify('+5 minutes');
        $lines = [self::line(1, 3, 12_300)];

        $order = Order::reconstitute(
            OrderId::fromString(self::ORDER),
            OrderNumber::fromSequence(100042),
            OrderOwner::customer('kc-sub-1'),
            self::contact(),
            ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa'),
            $lines,
            OrderStatus::New,
            IdempotencyKey::fromString('key-1'),
            self::fingerprint(),
            $this->now,
            $sentAt,
            7,
        );

        self::assertSame('R-100042', $order->number()->toString());
        self::assertSame('kc-sub-1', $order->owner()->customerId());
        self::assertSame(36_900, $order->totals()->gross()->amount());
        self::assertEquals($sentAt, $order->confirmationSentAt());
        self::assertSame(7, $order->version());
        self::assertSame([], $order->releaseEvents());
    }

    /**
     * @param list<OrderLine> $lines
     */
    private function place(array $lines): Order
    {
        return Order::place(
            OrderId::fromString(self::ORDER),
            OrderNumber::fromSequence(100001),
            OrderOwner::guest(),
            self::contact(),
            ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa'),
            $lines,
            IdempotencyKey::fromString('key-1'),
            self::fingerprint(),
            $this->now,
        );
    }

    private static function fingerprint(): RequestFingerprint
    {
        return RequestFingerprint::of('guest:cart', self::contact(), ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa'), 100);
    }

    private static function contact(): Contact
    {
        return Contact::of(EmailAddress::fromString('jan@example.com'), 'Jan Kowalski', '600100200');
    }

    private static function line(int $variant, int $quantity, int $gross): OrderLine
    {
        return OrderLine::of(
            VariantId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $variant)),
            ProductId::fromString(self::PRODUCT),
            'Buty Asfalt',
            '',
            $quantity,
            PriceCopy::of($gross, 0, $gross, 0, 'zero', Currency::PLN),
        );
    }
}
