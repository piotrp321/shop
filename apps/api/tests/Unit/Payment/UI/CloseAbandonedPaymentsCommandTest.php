<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\UI;

use App\Ordering\Application\Query\GetOrderPayability;
use App\Ordering\Application\Query\GetOrderPayabilityHandler;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId as OrderingOrderId;
use App\Ordering\Domain\OrderRepository;
use App\Payment\Application\AbandonedPayments;
use App\Payment\Application\Command\CloseAbandonedPayment;
use App\Payment\Application\Command\CloseAbandonedPaymentHandler;
use App\Payment\Application\PaymentProviders;
use App\Payment\Domain\IdempotencyKey as PaymentIdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RequestFingerprint;
use App\Payment\Infrastructure\Ordering\QueryBusPayableOrders;
use App\Payment\UI\Console\CloseAbandonedPaymentsCommand;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\SpyLogger;
use App\Tests\Unit\Ordering\VersionedOrders;
use App\Tests\Unit\Payment\FakePaymentProvider;
use App\Tests\Unit\Payment\FixedPaymentClock;
use App\Tests\Unit\Payment\InMemoryPayments;
use App\Tests\Unit\Payment\PaymentExamples;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * payment:close-abandoned with Ordering's GetOrderPayability on a real bus and the payments and the provider in memory
 * (spec: docs/features/async-reliability/spec.md, W6): an order that exists but cannot be read (its stored data breaks
 * an invariant of the domain made stricter later) is not an unknown order. Its payment is not closed and its session is
 * not cancelled at the provider (no rollback undoes that); the error is logged with the ids, the other payments are
 * closed and the exit code is 2.
 */
final class CloseAbandonedPaymentsCommandTest extends TestCase
{
    private const string UNREADABLE_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000bb';
    private const string UNREADABLE_PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000a2';

    public function testThePaymentOfAnOrderThatCannotBeReadIsNotClosedAndItsSessionNotCancelled(): void
    {
        // Both orders are past their deadline (12:30), so neither can be paid; the second one cannot be read.
        $orders = new VersionedOrders();
        $orders->add(OrderExamples::placed());
        $unreadable = $this->unreadable($orders, self::UNREADABLE_ORDER);
        $payments = new InMemoryPayments();
        $payments->add(self::payment(OrderExamples::ORDER, PaymentExamples::PAYMENT, PaymentExamples::KEY, PaymentExamples::SESSION));
        $payments->add(self::payment(self::UNREADABLE_ORDER, self::UNREADABLE_PAYMENT, 'pay-0192f0c4-0002', 'tx_0002'));
        $transfer = new FakePaymentProvider(ProviderCode::DemoTransfer);
        $logger = new SpyLogger();
        $queryBus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            GetOrderPayability::class => [new GetOrderPayabilityHandler($unreadable, new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 13:00:00+00:00')))],
        ]))]);
        $commandBus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CloseAbandonedPayment::class => [new CloseAbandonedPaymentHandler($payments, new PaymentProviders([$transfer, new FakePaymentProvider(ProviderCode::DemoCard)]), new FixedPaymentClock(new \DateTimeImmutable('2026-11-05 13:00:00+00:00')), $logger)],
        ]))]);
        $application = new Application();
        $application->addCommand(new CloseAbandonedPaymentsCommand(new AbandonedPayments($payments, new QueryBusPayableOrders($queryBus)), $commandBus, $this->doctrine(), $logger));
        $tester = new CommandTester($application->find('payment:close-abandoned'));

        $exitCode = $tester->execute([]);

        self::assertSame([PaymentExamples::SESSION], $transfer->cancelled, 'only the session of the order that was read is cancelled');
        self::assertSame(PaymentStatus::Pending, $payments->get(self::UNREADABLE_PAYMENT)->status());
        self::assertSame(PaymentStatus::Cancelled, $payments->get(PaymentExamples::PAYMENT)->status());
        self::assertSame(2, $exitCode, $tester->getDisplay());
        $errors = array_values(array_filter($logger->records, static fn (array $record): bool => 'error' === $record[0]));
        self::assertCount(1, $errors);
        self::assertSame(self::UNREADABLE_ORDER, $errors[0][2]['orderId'] ?? null);
        self::assertSame(self::UNREADABLE_PAYMENT, $errors[0][2]['paymentId'] ?? null);
    }

    /**
     * The orders, with one that exists but whose reconstitution fails as on data that breaks a stricter invariant.
     */
    private function unreadable(VersionedOrders $orders, string $orderId): OrderRepository
    {
        return new readonly class($orders, $orderId) implements OrderRepository {
            public function __construct(private VersionedOrders $orders, private string $unreadable)
            {
            }

            public function add(Order $order): void
            {
                $this->orders->add($order);
            }

            public function save(Order $order): void
            {
                $this->orders->save($order);
            }

            public function get(OrderingOrderId $id): Order
            {
                if ($id->toString() === $this->unreadable) {
                    throw new \InvalidArgumentException('A tax class has 1 to 20 characters.');
                }

                return $this->orders->get($id);
            }

            public function lock(OrderingOrderId $id): Order
            {
                return $this->get($id);
            }

            public function findByIdempotencyKey(IdempotencyKey $key): ?Order
            {
                return null;
            }
        };
    }

    /**
     * A pending payment of the order with a session at demo-transfer.
     */
    private static function payment(string $orderId, string $paymentId, string $key, string $session): Payment
    {
        $order = OrderId::fromString($orderId);
        $payment = Payment::start(PaymentId::fromString($paymentId), $order, ProviderCode::DemoTransfer, PaymentExamples::amount(), PaymentIdempotencyKey::fromString($key), RequestFingerprint::of($order, ProviderCode::DemoTransfer), new \DateTimeImmutable(PaymentExamples::CREATED));
        $payment->attachProviderSession(ProviderPaymentId::fromString($session), PaymentExamples::REDIRECT);

        return $payment;
    }

    private function doctrine(): ManagerRegistry
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($manager);

        return $doctrine;
    }
}
