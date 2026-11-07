<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Infrastructure;

use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\OrderChangedConcurrently;
use App\Ordering\Application\Command\OrderToPayNotFound;
use App\Payment\Application\OrderBusy;
use App\Payment\Application\OrderPaymentResult;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;
use App\Payment\Infrastructure\Ordering\CommandBusOrderPayments;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The port OrderPayments through Ordering's MarkOrderPaid on command.bus: the command carries the payment, its
 * amount in grosze with the currency and the moment of execution; each result of Ordering becomes the result of
 * Payment, a version conflict becomes OrderBusy and any other failure passes on as its cause.
 */
final class CommandBusOrderPaymentsTest extends TestCase
{
    private const string EXECUTED = '2026-11-05 12:19:30+00:00';

    /** @var list<object> */
    private array $dispatched = [];

    public function testDispatchesMarkOrderPaidWithThePayment(): void
    {
        $this->markPaid(static fn (): Envelope => self::handled(MarkOrderPaidResult::Paid));

        self::assertEquals([new MarkOrderPaid(PaymentExamples::ORDER, PaymentExamples::PAYMENT, PaymentExamples::TOTAL, 'PLN', new \DateTimeImmutable(self::EXECUTED))], $this->dispatched);
    }

    /**
     * @return iterable<string, array{MarkOrderPaidResult, OrderPaymentResult}>
     */
    public static function results(): iterable
    {
        yield 'paid' => [MarkOrderPaidResult::Paid, OrderPaymentResult::Paid];
        yield 'already paid' => [MarkOrderPaidResult::AlreadyPaid, OrderPaymentResult::AlreadyPaid];
        yield 'paid by another payment' => [MarkOrderPaidResult::PaidByAnotherPayment, OrderPaymentResult::PaidByAnotherPayment];
        yield 'paid after the deadline' => [MarkOrderPaidResult::PaidAfterDeadline, OrderPaymentResult::PaidAfterDeadline];
        yield 'the order cancelled' => [MarkOrderPaidResult::OrderCancelled, OrderPaymentResult::OrderCancelled];
    }

    #[DataProvider('results')]
    public function testEveryResultOfOrderingIsTranslated(MarkOrderPaidResult $result, OrderPaymentResult $expected): void
    {
        self::assertSame($expected, $this->markPaid(static fn (): Envelope => self::handled($result)));
    }

    public function testAVersionConflictIsOrderBusy(): void
    {
        $conflict = new OrderChangedConcurrently();

        try {
            $this->markPaid(static fn (Envelope $envelope): never => throw new HandlerFailedException($envelope, [$conflict]));
            self::fail('A version conflict passed.');
        } catch (OrderBusy $busy) {
            self::assertSame($conflict, $busy->getPrevious());
        }
    }

    public function testAnyOtherFailurePassesOnAsItsCause(): void
    {
        $notFound = new OrderToPayNotFound();

        try {
            $this->markPaid(static fn (Envelope $envelope): never => throw new HandlerFailedException($envelope, [$notFound]));
            self::fail('A failure of Ordering passed.');
        } catch (OrderToPayNotFound $thrown) {
            self::assertSame($notFound, $thrown);
        }
    }

    /**
     * @param \Closure(Envelope): Envelope $answer
     */
    private function markPaid(\Closure $answer): OrderPaymentResult
    {
        $bus = new class($answer) implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            /**
             * @param \Closure(Envelope): Envelope $answer
             */
            public function __construct(private readonly \Closure $answer)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return ($this->answer)(Envelope::wrap($message, $stamps));
            }
        };

        try {
            return new CommandBusOrderPayments($bus)->markPaid(OrderId::fromString(PaymentExamples::ORDER), PaymentId::fromString(PaymentExamples::PAYMENT), PaymentExamples::amount(), new \DateTimeImmutable(self::EXECUTED));
        } finally {
            $this->dispatched = $bus->dispatched;
        }
    }

    private static function handled(MarkOrderPaidResult $result): Envelope
    {
        return new Envelope(new \stdClass(), [new HandledStamp($result, 'handler')]);
    }
}
