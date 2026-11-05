<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\EventHandler\SendOrderConfirmation;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderPlaced;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\SpyOrderConfirmationMailer;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class SendOrderConfirmationTest extends TestCase
{
    private VersionedOrders $orders;
    private SpyOrderConfirmationMailer $mailer;
    private FixedOrderingClock $clock;
    private SendOrderConfirmation $handler;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->mailer = new SpyOrderConfirmationMailer();
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:00:05+00:00'));
        $this->handler = new SendOrderConfirmation($this->orders, $this->mailer, $this->clock);
    }

    public function testItSendsTheConfirmationAndMarksTheOrder(): void
    {
        ($this->handler)($this->event());

        self::assertCount(1, $this->mailer->sent);
        self::assertSame(OrderExamples::ORDER, $this->mailer->sent[0]->orderId);
        self::assertSame('R-100001', $this->mailer->sent[0]->number);
        self::assertEquals($this->clock->now, $this->stored()->confirmationSentAt());
    }

    public function testASecondDeliverySendsNothing(): void
    {
        ($this->handler)($this->event());
        $this->clock->now = new \DateTimeImmutable('2026-11-05 12:10:00+00:00');

        ($this->handler)($this->event());

        self::assertCount(1, $this->mailer->sent);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:05+00:00'), $this->stored()->confirmationSentAt());
    }

    public function testAnUnknownOrderIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            ($this->handler)(new OrderPlaced('0192f0c4-7c1a-7c4e-9a6e-0000000000ff', $this->clock->now));
        } finally {
            self::assertSame([], $this->mailer->sent);
        }
    }

    public function testAFailedSendLeavesTheOrderUnmarkedAndTheErrorPropagates(): void
    {
        $this->mailer->failure = new \RuntimeException('SMTP is down');

        try {
            ($this->handler)($this->event());
            self::fail('Expected the send failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('SMTP is down', $e->getMessage());
        }
        self::assertNull($this->stored()->confirmationSentAt());

        // The retry sends once the transport works again.
        $this->mailer->failure = null;
        ($this->handler)($this->event());
        self::assertCount(1, $this->mailer->sent);
        self::assertNotNull($this->stored()->confirmationSentAt());
    }

    public function testAVersionConflictOnTheMarkerThrowsAndTheRetrySendsNothing(): void
    {
        $competitorAt = new \DateTimeImmutable('2026-11-05 12:00:03+00:00');
        // Another handler of the same event stores the marker while this one is sending.
        $this->mailer->whileSending = fn () => $this->orders->confirmElsewhere(OrderId::fromString(OrderExamples::ORDER), $competitorAt);

        try {
            ($this->handler)($this->event());
            self::fail('Expected OrderModifiedConcurrently.');
        } catch (OrderModifiedConcurrently) {
            $this->addToAssertionCount(1);
        }
        self::assertCount(1, $this->mailer->sent);

        $this->mailer->whileSending = null;
        ($this->handler)($this->event());

        self::assertSame(1, $this->mailer->count(), 'the retry sees the marker of the other handler');
        self::assertEquals($competitorAt, $this->stored()->confirmationSentAt());
    }

    private function event(): OrderPlaced
    {
        return new OrderPlaced(OrderExamples::ORDER, new \DateTimeImmutable('2026-11-05 12:00:00+00:00'));
    }

    private function stored(): Order
    {
        return $this->orders->get(OrderId::fromString(OrderExamples::ORDER));
    }
}
