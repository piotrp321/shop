<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\EventHandler\NotificationSender;
use App\Ordering\Application\EventHandler\SendOrderCancelled;
use App\Ordering\Application\EventHandler\SendOrderConfirmation;
use App\Ordering\Application\EventHandler\SendOrderShipped;
use App\Ordering\Application\EventHandler\SendPaymentReceived;
use App\Ordering\Application\EventHandler\SendReturnReceived;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Domain\OrderReturned;
use App\Ordering\Domain\OrderShipped;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\StaffActor;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryOrderNotifications;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\SpyOrderConfirmationMailer;
use App\Tests\Unit\Ordering\SpyOrderStatusMailer;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * SendOrderConfirmation (OrderPlaced), SendPaymentReceived (OrderPaid), SendOrderCancelled (OrderCancelled),
 * SendOrderShipped (OrderShipped) and SendReturnReceived (OrderReturned): each email of an order goes out once, under
 * a claim of its sending (OrderNotifications), and never changes the order.
 */
final class SendOrderEmailsTest extends TestCase
{
    private const string NOW = '2026-11-05 12:40:00+00:00';

    private VersionedOrders $orders;
    private InMemoryOrderNotifications $notifications;
    private SpyOrderConfirmationMailer $confirmationMailer;
    private SpyOrderStatusMailer $statusMailer;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->notifications = new InMemoryOrderNotifications();
        $this->confirmationMailer = new SpyOrderConfirmationMailer();
        $this->statusMailer = new SpyOrderStatusMailer();
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return iterable<string, array{NotificationKind}>
     */
    public static function kinds(): iterable
    {
        yield 'confirmation' => [NotificationKind::Confirmation];
        yield 'payment received' => [NotificationKind::PaymentReceived];
        yield 'order cancelled' => [NotificationKind::Cancelled];
        yield 'order shipped' => [NotificationKind::Shipped];
        yield 'return received' => [NotificationKind::ReturnReceived];
    }

    #[DataProvider('kinds')]
    public function testItSendsTheEmailAndMarksItSentWithoutChangingTheOrder(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        $version = $this->stored()->version();
        $this->orders->saved = [];

        $this->handle($kind);

        self::assertSame([$kind->value], $this->sentKinds());
        self::assertSame(OrderExamples::ORDER, $this->sentOrders()[0]->orderId);
        self::assertSame('R-100001', $this->sentOrders()[0]->number);
        self::assertEquals($this->clock->now, $this->notifications->sentAt(OrderExamples::ORDER, $kind));
        self::assertNull($this->notifications->claimedUntil(OrderExamples::ORDER, $kind));
        self::assertSame([], $this->orders->saved, 'the order is not saved');
        self::assertSame($version, $this->stored()->version());
        foreach (NotificationKind::cases() as $other) {
            if ($other !== $kind) {
                self::assertNull($this->notifications->sentAt(OrderExamples::ORDER, $other), $other->value);
            }
        }
    }

    #[DataProvider('kinds')]
    public function testAnEmailAlreadySentIsNotSentAgain(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        $this->handle($kind);
        $first = $this->clock->now;
        $this->clock->now = $first->modify('+10 minutes');

        $this->handle($kind);

        self::assertCount(1, $this->sentKinds());
        self::assertEquals($first, $this->notifications->sentAt(OrderExamples::ORDER, $kind));
    }

    #[DataProvider('kinds')]
    public function testAHeldClaimIsRetriedOneSecondAfterTheEndOfTheLease(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        // Another handler claimed the sending a minute ago: its lease ends in 4 minutes.
        $this->notifications->claim(OrderId::fromString(OrderExamples::ORDER), $kind, $this->clock->now->modify('-1 minute'));

        try {
            $this->handle($kind);
            self::fail('Expected RecoverableMessageHandlingException.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame((4 * 60 + 1) * 1000, $e->getRetryDelay());
            self::assertTrue($e->forceRetry(), 'retried however many times it waits');
        }

        self::assertSame([], $this->sentKinds());
        self::assertEquals($this->clock->now->modify('+4 minutes'), $this->notifications->claimedUntil(OrderExamples::ORDER, $kind), 'the claim of the other handler stays');
        self::assertSame([], $this->notifications->releases);
    }

    #[DataProvider('kinds')]
    public function testAClaimHeldWithAFractionOfASecondIsRetriedAfterTheLease(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        $this->notifications->claim(OrderId::fromString(OrderExamples::ORDER), $kind, new \DateTimeImmutable('2026-11-05 12:36:00+00:00'));
        $this->clock->now = new \DateTimeImmutable('2026-11-05 12:40:59.750+00:00');

        try {
            $this->handle($kind);
            self::fail('Expected RecoverableMessageHandlingException.');
        } catch (RecoverableMessageHandlingException $e) {
            // The lease ends at 12:41:00, the retry comes at 12:41:01.
            self::assertSame(1250, $e->getRetryDelay());
        }
    }

    #[DataProvider('kinds')]
    public function testAnExpiredLeaseSends(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        // A handler that claimed the sending 5 minutes ago died before it marked the email sent.
        $this->notifications->claim(OrderId::fromString(OrderExamples::ORDER), $kind, $this->clock->now->modify('-5 minutes'));

        $this->handle($kind);

        self::assertSame([$kind->value], $this->sentKinds());
        self::assertEquals($this->clock->now, $this->notifications->sentAt(OrderExamples::ORDER, $kind));
    }

    #[DataProvider('kinds')]
    public function testAFailedSendReleasesTheClaimAndTheRetrySendsOnce(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        $this->failTheMailer(new \RuntimeException('SMTP is down'));

        try {
            $this->handle($kind);
            self::fail('Expected the send failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('SMTP is down', $e->getMessage());
        }
        self::assertNull($this->notifications->sentAt(OrderExamples::ORDER, $kind));
        self::assertNull($this->notifications->claimedUntil(OrderExamples::ORDER, $kind), 'released');
        self::assertSame([$kind->value.' '.OrderExamples::ORDER], $this->notifications->releases);

        // The retry, one second later, sends once the transport works again.
        $this->failTheMailer(null);
        $this->clock->now = $this->clock->now->modify('+1 second');
        $this->handle($kind);

        self::assertSame([$kind->value], $this->sentKinds());
        self::assertEquals($this->clock->now, $this->notifications->sentAt(OrderExamples::ORDER, $kind));
    }

    #[DataProvider('kinds')]
    public function testADeliveryWhileTheSendingIsInProgressSendsNothing(NotificationKind $kind): void
    {
        $this->changeTheOrderFor($kind);
        $concurrent = null;
        // The same event delivered again while the first handler is sending.
        $this->whileSending(function () use ($kind, &$concurrent): void {
            try {
                $this->handle($kind);
            } catch (RecoverableMessageHandlingException $e) {
                $concurrent = $e;
            }
        });

        $this->handle($kind);

        self::assertInstanceOf(RecoverableMessageHandlingException::class, $concurrent);
        self::assertSame([$kind->value], $this->sentKinds());

        // Its retry, after the lease, finds the email sent.
        $this->whileSending(null);
        $this->clock->now = $this->clock->now->modify('+5 minutes 1 second');
        $this->handle($kind);
        self::assertSame([$kind->value], $this->sentKinds());
    }

    #[DataProvider('kinds')]
    public function testAnUnknownOrderIsUnrecoverableAndClaimsNothing(NotificationKind $kind): void
    {
        $unknown = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';

        try {
            $this->handle($kind, $unknown);
            self::fail('Expected UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame([], $this->sentKinds());
        self::assertFalse($this->notifications->touched($unknown));
    }

    public function testACancellationByStaffSendsItsOwnEmailOnce(): void
    {
        $this->changeTheOrderFor(NotificationKind::PaymentReceived);
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->cancel(StaffActor::of('kc-staff-1', 'anna'), 'Brak towaru', new \DateTimeImmutable('2026-11-05 13:00:00+00:00')));
        $event = new OrderCancelled(OrderExamples::ORDER, 'staff', new \DateTimeImmutable('2026-11-05 13:00:00+00:00'));
        $handler = new SendOrderCancelled($this->orders, new NotificationSender($this->notifications, $this->clock), $this->statusMailer);

        $handler($event);
        $handler($event);

        self::assertSame(['order_cancelled_by_staff'], array_map(static fn (array $sent): string => $sent[0], $this->statusMailer->sent), 'one email, not the one about the payment');
        self::assertEquals($this->clock->now, $this->notifications->sentAt(OrderExamples::ORDER, NotificationKind::Cancelled));
    }

    private function changeTheOrderFor(NotificationKind $kind): void
    {
        $paid = static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
        $staff = StaffActor::of('kc-staff-1', 'anna');
        $shipped = static function (Order $order) use ($paid, $staff): void {
            $paid($order);
            $order->ship($staff, null, new \DateTimeImmutable('2026-11-05 12:20:00+00:00'));
        };
        $change = match ($kind) {
            NotificationKind::PaymentReceived => $paid,
            NotificationKind::Cancelled => static fn (Order $order) => $order->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00')),
            NotificationKind::Shipped => $shipped,
            NotificationKind::ReturnReceived => static function (Order $order) use ($shipped, $staff): void {
                $shipped($order);
                $order->registerReturn($staff, 'Paczka wróciła', new \DateTimeImmutable('2026-11-05 12:30:00+00:00'));
            },
            default => null,
        };
        if (null !== $change) {
            $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), $change);
        }
    }

    private function handle(NotificationKind $kind, string $orderId = OrderExamples::ORDER): void
    {
        $sender = new NotificationSender($this->notifications, $this->clock);
        match ($kind) {
            NotificationKind::Confirmation => new SendOrderConfirmation($this->orders, $sender, $this->confirmationMailer)(new OrderPlaced($orderId, new \DateTimeImmutable('2026-11-05 12:00:00+00:00'))),
            NotificationKind::PaymentReceived => new SendPaymentReceived($this->orders, $sender, $this->statusMailer)(new OrderPaid($orderId, new \DateTimeImmutable('2026-11-05 12:10:05+00:00'))),
            NotificationKind::Cancelled => new SendOrderCancelled($this->orders, $sender, $this->statusMailer)(new OrderCancelled($orderId, 'payment_timeout', new \DateTimeImmutable('2026-11-05 12:35:00+00:00'))),
            NotificationKind::Shipped => new SendOrderShipped($this->orders, $sender, $this->statusMailer)(new OrderShipped($orderId, new \DateTimeImmutable('2026-11-05 12:20:00+00:00'))),
            NotificationKind::ReturnReceived => new SendReturnReceived($this->orders, $sender, $this->statusMailer)(new OrderReturned($orderId, new \DateTimeImmutable('2026-11-05 12:30:00+00:00'))),
        };
    }

    private function failTheMailer(?\Throwable $failure): void
    {
        $this->confirmationMailer->failure = $failure;
        $this->statusMailer->failure = $failure;
    }

    private function whileSending(?\Closure $meanwhile): void
    {
        $this->confirmationMailer->whileSending = $meanwhile;
        $this->statusMailer->whileSending = $meanwhile;
    }

    /**
     * @return list<string> the kinds of the emails sent, in order (`confirmation`, `payment_received`, `cancelled`)
     */
    private function sentKinds(): array
    {
        return [
            ...array_map(static fn (): string => NotificationKind::Confirmation->value, $this->confirmationMailer->sent),
            ...array_map(static fn (array $sent): string => 'order_cancelled' === $sent[0] ? NotificationKind::Cancelled->value : $sent[0], $this->statusMailer->sent),
        ];
    }

    /**
     * @return list<\App\Ordering\Application\View\OrderView>
     */
    private function sentOrders(): array
    {
        return [...$this->confirmationMailer->sent, ...array_map(static fn (array $sent) => $sent[1], $this->statusMailer->sent)];
    }

    private function stored(): Order
    {
        return $this->orders->get(OrderId::fromString(OrderExamples::ORDER));
    }
}
