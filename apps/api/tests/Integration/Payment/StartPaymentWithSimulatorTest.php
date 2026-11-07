<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\Payment\Domain\PaymentInProgress;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * StartPayment on command.bus against the simulator through KernelForwardingHttpClient (another kernel, the same
 * transaction of DAMA): a start opens a session, a second start cancels the pending one at the simulator, and a
 * session already paid there stops a new start with PaymentInProgress.
 */
final class StartPaymentWithSimulatorTest extends KernelTestCase
{
    private Connection $connection;
    private MessageBusInterface $commandBus;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $commandBus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $commandBus);
        $this->connection = $connection;
        $this->commandBus = $commandBus;
    }

    public function testAStartOpensASessionAtTheSimulator(): void
    {
        [$orderId, $token, $number] = $this->order();

        $started = $this->start($orderId, $token, 'demo-card', 'first');

        $session = $this->sessionOf($started);
        self::assertSame(['demo-card', $number, PaymentFixture::GROSS, 'open'], [$session['provider'], $session['reference'], $session['amount'], $session['status']]);
        self::assertSame($this->connection->fetchOne('SELECT payment_deadline FROM ordering_order WHERE id = ?', [$orderId]), $session['expires_at']);
    }

    public function testASecondStartCancelsThePendingSession(): void
    {
        [$orderId, $token] = $this->order();
        $first = $this->start($orderId, $token, 'demo-transfer', 'first');

        $second = $this->start($orderId, $token, 'demo-transfer', 'second');

        self::assertSame('cancelled', $this->sessionOf($first)['status']);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM payment_payment WHERE id = ?', [$first->payment->paymentId]));
        self::assertSame('open', $this->sessionOf($second)['status']);
        self::assertSame('pending', $second->payment->status);
    }

    public function testASessionPaidAtTheSimulatorStopsANewStart(): void
    {
        [$orderId, $token] = $this->order();
        $first = $this->start($orderId, $token, 'demo-card', 'first');
        $this->connection->executeStatement("UPDATE payment_simulator_transaction SET status = 'paid', decided_at = now() WHERE id = ?", [$this->sessionOf($first)['id']]);

        try {
            $this->start($orderId, $token, 'demo-transfer', 'second');
            self::fail('A new payment was started while the first was paid.');
        } catch (HandlerFailedException $failure) {
            $wrapped = array_values($failure->getWrappedExceptions());
            self::assertCount(1, $wrapped);
            self::assertInstanceOf(PaymentInProgress::class, $wrapped[0]);
        }

        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM payment_payment WHERE id = ?', [$first->payment->paymentId]));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM payment_payment WHERE order_id = ?', [$orderId]));
    }

    /**
     * @return array{string, string, string} the id, the access token and the number of a new guest order
     */
    private function order(): array
    {
        [$placement] = PaymentFixture::placement($this->connection);
        $order = PaymentFixture::placed($this->commandBus->dispatch($placement)->last(HandledStamp::class)?->getResult())->order;
        self::assertNotNull($order->accessToken);

        return [$order->orderId, $order->accessToken, $order->number];
    }

    private function start(string $orderId, string $token, string $provider, string $key): StartedPayment
    {
        $started = $this->commandBus->dispatch(new StartPayment($orderId, PaymentAccess::guest($token), $provider, $key))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StartedPayment::class, $started);

        return $started;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionOf(StartedPayment $started): array
    {
        $row = $this->connection->fetchAssociative('SELECT t.* FROM payment_simulator_transaction t JOIN payment_payment p ON p.provider_payment_id = t.id::text WHERE p.id = ?', [$started->payment->paymentId]);
        self::assertIsArray($row);

        return $row;
    }
}
