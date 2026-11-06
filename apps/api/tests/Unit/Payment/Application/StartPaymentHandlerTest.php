<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\Command\StartPaymentHandler;
use App\Payment\Application\OrderNotFound;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\PaymentProviders;
use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\SessionCancellation;
use App\Payment\Application\View\PaymentView;
use App\Payment\Domain\Currency;
use App\Payment\Domain\InvalidPaymentInput;
use App\Payment\Domain\OrderAlreadyPaid;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderState;
use App\Payment\Domain\OrderToPay;
use App\Payment\Domain\PaymentDeadlinePassed;
use App\Payment\Domain\PaymentIdempotencyKeyReused;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\TypedProblem;
use App\Shared\Domain\Exception\Unavailable;
use App\Tests\Unit\Payment\FakePaymentProvider;
use App\Tests\Unit\Payment\FixedPaymentClock;
use App\Tests\Unit\Payment\InMemoryPayableOrders;
use App\Tests\Unit\Payment\InMemoryPayments;
use App\Tests\Unit\Payment\PaymentExamples;
use App\Tests\Unit\Payment\RefusingPayments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StartPayment: the access to the order before anything else, then the replay, the state of the order, the
 * cancellation of the pending payment at its provider and the new payment with its session.
 */
final class StartPaymentHandlerTest extends TestCase
{
    private const string TOKEN = 'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM';
    private const string DEADLINE = '2026-11-05 12:30:00+00:00';
    private const string NOW = '2026-11-05 12:10:00+00:00';
    private const string STORE = 'http://localhost:3000';

    private InMemoryPayments $payments;
    private InMemoryPayableOrders $orders;
    private FakePaymentProvider $transfer;
    private FakePaymentProvider $card;
    private FixedPaymentClock $clock;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->orders = new InMemoryPayableOrders();
        $this->orders->add(self::order(), PaymentAccess::guest(self::TOKEN));
        $this->transfer = new FakePaymentProvider(ProviderCode::DemoTransfer);
        $this->card = new FakePaymentProvider(ProviderCode::DemoCard);
        $this->clock = new FixedPaymentClock(new \DateTimeImmutable(self::NOW));
    }

    public function testStartsAPendingPaymentWithASessionThatExpiresAtTheDeadline(): void
    {
        $started = $this->start();

        self::assertFalse($started->replayed);
        $payment = $started->payment;
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $payment->paymentId);
        self::assertEquals(new PaymentView($payment->paymentId, 'demo-transfer', 'pending', 'http://provider.test/pay/demo_transfer_1'), $payment);

        self::assertCount(1, $this->transfer->created);
        [$paymentId, $amount, $number, $returnUrl, $expiresAt] = $this->transfer->created[0];
        self::assertSame($payment->paymentId, $paymentId->toString());
        self::assertSame(PaymentExamples::TOTAL, $amount->minorUnits());
        self::assertSame('R-100001', $number);
        self::assertSame(self::STORE.'/checkout/confirmation', $returnUrl, 'the API builds the address, the store gives none');
        self::assertEquals(new \DateTimeImmutable(self::DEADLINE), $expiresAt);
        self::assertSame([], $this->card->created);

        $stored = $this->payments->get($payment->paymentId);
        self::assertSame(PaymentStatus::Pending, $stored->status());
        self::assertSame(PaymentExamples::ORDER, $stored->orderId()->toString());
        self::assertSame(PaymentExamples::KEY, $stored->idempotencyKey()->toString());
        self::assertSame('demo_transfer_1', $stored->providerPaymentId()?->toString());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $stored->createdAt());
        self::assertSame(['lock '.PaymentExamples::ORDER, 'add '.$payment->paymentId, 'save '.$payment->paymentId.' pending'], $this->payments->journal, 'stored before the call to the provider, then with its session');
    }

    public function testACustomerStartsAPaymentOfOwnOrder(): void
    {
        $this->orders->add(self::order(), PaymentAccess::customer('customer-1'));

        $started = $this->start(PaymentAccess::customer('customer-1'), 'demo-card');

        self::assertSame('demo-card', $started->payment->provider);
        self::assertCount(1, $this->card->created);
    }

    /**
     * @return iterable<string, array{string, PaymentAccess}>
     */
    public static function withoutAccess(): iterable
    {
        yield 'unknown order' => ['0192f0c4-7c1a-7c4e-9a6e-0000000000ff', PaymentAccess::guest(self::TOKEN)];
        yield 'not a UUID' => ['R-100001', PaymentAccess::guest(self::TOKEN)];
        yield 'another token' => [PaymentExamples::ORDER, PaymentAccess::guest('another-token')];
        yield 'a customer' => [PaymentExamples::ORDER, PaymentAccess::customer('customer-1')];
    }

    #[DataProvider('withoutAccess')]
    public function testWithoutAccessToTheOrderItIsNotFound(string $orderId, PaymentAccess $access): void
    {
        try {
            $this->start($access, orderId: $orderId);
            self::fail('A payment was started without access.');
        } catch (NotFound $notFound) {
            self::assertInstanceOf(OrderNotFound::class, $notFound);
            self::assertSame('Nie znaleźliśmy zamówienia.', $notFound->getMessage());
        }

        $this->assertNothingStarted();
    }

    public function testTheSameKeyForTheSameOrderAndProviderIsAReplay(): void
    {
        $first = $this->start();
        $this->transfer->created = [];

        $replay = $this->start();

        self::assertTrue($replay->replayed);
        self::assertEquals($first->payment, $replay->payment);
        self::assertSame([], $this->transfer->created);
        self::assertCount(1, $this->payments->all());
    }

    public function testAReplayGivesThePaymentAsItIsNow(): void
    {
        $first = $this->start();
        $stored = $this->payments->get($first->payment->paymentId);
        $stored->fail(new \DateTimeImmutable(self::NOW));
        $this->payments->save($stored);
        $this->orders->add(self::order(OrderState::Paid, payable: false), PaymentAccess::guest(self::TOKEN));

        $replay = $this->start();

        self::assertTrue($replay->replayed);
        self::assertSame('failed', $replay->payment->status, 'the replay comes before the state of the order');
    }

    /**
     * The key alone never gives a payment: the access is checked first.
     *
     * @return iterable<string, array{PaymentAccess}>
     */
    public static function otherCallers(): iterable
    {
        yield 'another token' => [PaymentAccess::guest('another-token')];
        yield 'another customer' => [PaymentAccess::customer('customer-2')];
    }

    #[DataProvider('otherCallers')]
    public function testAReplayByAnotherCallerIsNotFound(PaymentAccess $access): void
    {
        $this->start();

        $this->expectException(OrderNotFound::class);

        $this->start($access);
    }

    public function testTheSameKeyForAnotherProviderOrAnotherOrderIsReused(): void
    {
        $this->start();
        $other = '0192f0c4-7c1a-7c4e-9a6e-0000000000d2';
        $this->orders->add(self::order(orderId: $other), PaymentAccess::guest(self::TOKEN));

        foreach ([['demo-card', PaymentExamples::ORDER], ['demo-transfer', $other]] as [$provider, $orderId]) {
            try {
                $this->start(provider: $provider, orderId: $orderId);
                self::fail('The key of another payment started one.');
            } catch (PaymentIdempotencyKeyReused $reused) {
                $this->assertConflict('payment-idempotency-key-reused', 'Ten klucz żądania był już użyty dla innej płatności.', $reused);
            }
        }

        self::assertCount(1, $this->payments->all());
        self::assertSame([], $this->card->created);
    }

    public function testAPaidOrderCannotBePaidAgain(): void
    {
        $this->orders->add(self::order(OrderState::Paid, payable: false), PaymentAccess::guest(self::TOKEN));

        try {
            $this->start();
            self::fail('A paid order started a payment.');
        } catch (OrderAlreadyPaid $paid) {
            $this->assertConflict('order-already-paid', 'Zamówienie jest już opłacone.', $paid);
        }

        $this->assertNothingStarted();
    }

    /**
     * @return iterable<string, array{OrderState}>
     */
    public static function pastTheDeadline(): iterable
    {
        yield 'new after the deadline' => [OrderState::New];
        yield 'cancelled' => [OrderState::Cancelled];
    }

    #[DataProvider('pastTheDeadline')]
    public function testAnOrderPastTheDeadlineCannotBePaid(OrderState $state): void
    {
        $this->orders->add(self::order($state, payable: false), PaymentAccess::guest(self::TOKEN));

        try {
            $this->start();
            self::fail('An order past its deadline started a payment.');
        } catch (PaymentDeadlinePassed $passed) {
            $this->assertConflict('payment-deadline-passed', 'Minął termin płatności za to zamówienie.', $passed);
        }

        $this->assertNothingStarted();
    }

    public function testAPendingPaymentCancelledAtItsProviderMakesWayForTheNewOne(): void
    {
        $first = $this->start(key: 'first');

        $second = $this->start(provider: 'demo-card', key: 'second');

        self::assertSame(['demo_transfer_1'], $this->transfer->cancelled, 'cancelled at its own provider');
        self::assertSame([], $this->card->cancelled);
        $old = $this->payments->get($first->payment->paymentId);
        self::assertSame(PaymentStatus::Cancelled, $old->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $old->completedAt());
        self::assertSame('pending', $second->payment->status);
        self::assertSame('demo-card', $second->payment->provider);
        self::assertSame('http://provider.test/pay/demo_card_1', $second->payment->redirectUrl);
        self::assertSame([
            'lock '.PaymentExamples::ORDER,
            'add '.$first->payment->paymentId,
            'save '.$first->payment->paymentId.' pending',
            'lock '.PaymentExamples::ORDER,
            'save '.$first->payment->paymentId.' cancelled',
            'add '.$second->payment->paymentId,
            'save '.$second->payment->paymentId.' pending',
        ], $this->payments->journal);
    }

    public function testAPendingPaymentAlreadyRejectedAtItsProviderFailsAndMakesWayForTheNewOne(): void
    {
        $first = $this->start(key: 'first');
        $this->transfer->cancellation = SessionCancellation::AlreadyRejected;

        $second = $this->start(key: 'second');

        self::assertSame(PaymentStatus::Failed, $this->payments->get($first->payment->paymentId)->status());
        self::assertSame('pending', $second->payment->status);
        self::assertSame('http://provider.test/pay/demo_transfer_2', $second->payment->redirectUrl);
    }

    public function testAPendingPaymentAlreadyPaidAtItsProviderStopsTheNewOneWithoutChange(): void
    {
        $first = $this->start(key: 'first');
        $this->transfer->cancellation = SessionCancellation::AlreadyPaid;

        try {
            $this->start(provider: 'demo-card', key: 'second');
            self::fail('A second payment was started while the first was paid.');
        } catch (PaymentInProgress $inProgress) {
            $this->assertConflict('payment-in-progress', 'Płatność została już wykonana. Czekamy na jej potwierdzenie.', $inProgress);
        }

        self::assertSame(PaymentStatus::Pending, $this->payments->get($first->payment->paymentId)->status(), 'the webhook brings the confirmation');
        self::assertCount(1, $this->payments->all());
        self::assertSame([], $this->card->created);
    }

    public function testAProviderThatCannotCancelStopsTheNewPaymentWithoutChange(): void
    {
        $first = $this->start(key: 'first');
        $this->transfer->cancelFails = true;

        try {
            $this->start(provider: 'demo-card', key: 'second');
            self::fail('A second payment was started without the cancellation of the first.');
        } catch (Unavailable $unavailable) {
            self::assertInstanceOf(PaymentProviderUnavailable::class, $unavailable);
        }

        self::assertSame(PaymentStatus::Pending, $this->payments->get($first->payment->paymentId)->status());
        self::assertCount(1, $this->payments->all());
        self::assertSame([], $this->card->created);
    }

    public function testAProviderThatCannotOpenASessionFailsTheStart(): void
    {
        $this->transfer->createFails = true;

        $this->expectException(PaymentProviderUnavailable::class);

        $this->start();
    }

    /**
     * A concurrent start wrote its payment meanwhile (the same key or another pending payment of the order): the
     * unique index refuses this one.
     */
    public function testAConcurrentStartRefusedByTheRepositoryIsInProgress(): void
    {
        try {
            $this->handler(new RefusingPayments())(new StartPayment(PaymentExamples::ORDER, PaymentAccess::guest(self::TOKEN), 'demo-transfer', PaymentExamples::KEY));
            self::fail('A concurrent start was not refused.');
        } catch (PaymentInProgress $inProgress) {
            $this->assertConflict('payment-in-progress', 'Płatność za to zamówienie jest w toku. Spróbuj za chwilę.', $inProgress);
        }

        self::assertSame([], $this->transfer->created, 'the provider is not called');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'unknown provider' => ['paypal', PaymentExamples::KEY];
        yield 'malformed key' => ['demo-transfer', 'a key'];
    }

    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRefusedBeforeAnything(string $provider, string $key): void
    {
        try {
            $this->start(provider: $provider, key: $key);
            self::fail('Invalid input started a payment.');
        } catch (InvalidPaymentInput) {
        }

        self::assertSame(0, $this->orders->asked);
        $this->assertNothingStarted();
    }

    private function start(?PaymentAccess $access = null, string $provider = 'demo-transfer', string $key = PaymentExamples::KEY, string $orderId = PaymentExamples::ORDER): StartedPayment
    {
        return $this->handler($this->payments)(new StartPayment($orderId, $access ?? PaymentAccess::guest(self::TOKEN), $provider, $key));
    }

    private function handler(PaymentRepository $payments): StartPaymentHandler
    {
        return new StartPaymentHandler($payments, $this->orders, new PaymentProviders([$this->transfer, $this->card]), $this->clock, self::STORE);
    }

    private static function order(OrderState $state = OrderState::New, bool $payable = true, string $orderId = PaymentExamples::ORDER): OrderToPay
    {
        return new OrderToPay(OrderId::fromString($orderId), 'R-100001', $state, PaymentExamples::TOTAL, Currency::PLN, new \DateTimeImmutable(self::DEADLINE), $payable);
    }

    private function assertNothingStarted(): void
    {
        self::assertSame([], $this->payments->all());
        self::assertSame([], $this->transfer->created);
        self::assertSame([], $this->card->created);
        self::assertSame([], $this->transfer->cancelled);
    }

    private function assertConflict(string $slug, string $detail, \Throwable $conflict): void
    {
        self::assertInstanceOf(Conflict::class, $conflict);
        self::assertInstanceOf(TypedProblem::class, $conflict);
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/'.$slug, $conflict->problemType());
        self::assertSame($detail, $conflict->getMessage());
    }
}
