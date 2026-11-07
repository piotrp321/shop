<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\EventHandler\SendOrderCancelled;
use App\Ordering\Application\EventHandler\SendPaymentReceived;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\PaymentId;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\SpyOrderStatusMailer;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * SendPaymentReceived (OrderPaid) and SendOrderCancelled (OrderCancelled): the pattern of SendOrderConfirmation, each
 * with its own marker on the order. The mail goes out before the marker is stored; a second delivery of the event
 * finds the marker and sends nothing.
 */
final class SendOrderStatusEmailsTest extends TestCase
{
    private VersionedOrders $orders;
    private SpyOrderStatusMailer $mailer;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->mailer = new SpyOrderStatusMailer();
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:40:00+00:00'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kinds(): iterable
    {
        yield 'payment received' => ['payment_received'];
        yield 'order cancelled' => ['order_cancelled'];
    }

    #[DataProvider('kinds')]
    public function testItSendsTheEmailAndMarksTheOrder(string $kind): void
    {
        $this->changeTheOrderFor($kind);

        $this->handle($kind);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame($kind, $this->mailer->sent[0][0]);
        self::assertSame(OrderExamples::ORDER, $this->mailer->sent[0][1]->orderId);
        self::assertEquals($this->clock->now, $this->marker($kind));
        self::assertNull($this->otherMarker($kind));
    }

    #[DataProvider('kinds')]
    public function testASecondDeliverySendsNothing(string $kind): void
    {
        $this->changeTheOrderFor($kind);
        $this->handle($kind);
        $first = $this->clock->now;
        $this->clock->now = $first->modify('+10 minutes');

        $this->handle($kind);

        self::assertCount(1, $this->mailer->sent);
        self::assertEquals($first, $this->marker($kind));
    }

    #[DataProvider('kinds')]
    public function testAnUnknownOrderIsUnrecoverable(string $kind): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            $this->handle($kind, '0192f0c4-7c1a-7c4e-9a6e-0000000000ff');
        } finally {
            self::assertSame([], $this->mailer->sent);
        }
    }

    #[DataProvider('kinds')]
    public function testAFailedSendLeavesTheOrderUnmarkedAndTheRetrySends(string $kind): void
    {
        $this->changeTheOrderFor($kind);
        $this->mailer->failure = new \RuntimeException('SMTP is down');

        try {
            $this->handle($kind);
            self::fail('Expected the send failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('SMTP is down', $e->getMessage());
        }
        self::assertNull($this->marker($kind));

        $this->mailer->failure = null;
        $this->handle($kind);
        self::assertCount(1, $this->mailer->sent);
        self::assertNotNull($this->marker($kind));
    }

    #[DataProvider('kinds')]
    public function testAVersionConflictOnTheMarkerThrowsAndTheRetrySendsNothing(string $kind): void
    {
        $this->changeTheOrderFor($kind);
        $competitorAt = new \DateTimeImmutable('2026-11-05 12:39:58+00:00');
        $this->mailer->whileSending = fn () => $this->orders->changeElsewhere(
            OrderId::fromString(OrderExamples::ORDER),
            'payment_received' === $kind
                ? static fn (Order $order) => $order->markPaymentNotificationSent($competitorAt)
                : static fn (Order $order) => $order->markCancellationNotificationSent($competitorAt),
        );

        try {
            $this->handle($kind);
            self::fail('Expected OrderModifiedConcurrently.');
        } catch (OrderModifiedConcurrently) {
            $this->addToAssertionCount(1);
        }

        $this->mailer->whileSending = null;
        $this->handle($kind);

        self::assertCount(1, $this->mailer->sent, 'the retry sees the marker of the other handler');
        self::assertEquals($competitorAt, $this->marker($kind));
    }

    private function changeTheOrderFor(string $kind): void
    {
        $this->orders->changeElsewhere(
            OrderId::fromString(OrderExamples::ORDER),
            'payment_received' === $kind
                ? static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'))
                : static fn (Order $order) => $order->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00')),
        );
    }

    private function handle(string $kind, string $orderId = OrderExamples::ORDER): void
    {
        if ('payment_received' === $kind) {
            new SendPaymentReceived($this->orders, $this->mailer, $this->clock)(new OrderPaid($orderId, new \DateTimeImmutable('2026-11-05 12:10:05+00:00')));

            return;
        }
        new SendOrderCancelled($this->orders, $this->mailer, $this->clock)(new OrderCancelled($orderId, 'payment_timeout', new \DateTimeImmutable('2026-11-05 12:35:00+00:00')));
    }

    private function marker(string $kind): ?\DateTimeImmutable
    {
        $order = $this->orders->get(OrderId::fromString(OrderExamples::ORDER));

        return 'payment_received' === $kind ? $order->paymentNotificationSentAt() : $order->cancellationNotificationSentAt();
    }

    private function otherMarker(string $kind): ?\DateTimeImmutable
    {
        return $this->marker('payment_received' === $kind ? 'order_cancelled' : 'payment_received');
    }
}
