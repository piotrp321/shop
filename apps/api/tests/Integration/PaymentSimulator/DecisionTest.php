<?php

declare(strict_types=1);

namespace App\Tests\Integration\PaymentSimulator;

use App\PaymentSimulator\CancelOutcome;
use App\PaymentSimulator\CancelResult;
use App\PaymentSimulator\CancelTransaction;
use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\DecisionOutcome;
use App\PaymentSimulator\DeliverSimulatedWebhook;
use App\PaymentSimulator\Provider;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Support\SimulatorTransactions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * The decision of the customer and the cancellation by the shop on shop_test with the Doctrine transport
 * `payment_simulator` (the `test_messaging` kernel; rolled back after each test): the state of the transaction and
 * the message that delivers its webhook are written in one transaction.
 */
final class DecisionTest extends MessagingKernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testAPaymentStoresThePaidStateAndExactlyOneDeliveryOfThatTransaction(): void
    {
        $transaction = SimulatorTransactions::transfer($this->entityManager);

        $outcome = $this->decide($transaction->id, Provider::DemoTransfer, true);

        self::assertEquals(new DecisionOutcome(true, true, 'https://shop.test/checkout/confirmation'), $outcome);
        self::assertSame(['paid', true], $this->state($transaction->id));
        self::assertEquals([new DeliverSimulatedWebhook($transaction->id)], $this->queuedDeliveries());
    }

    public function testARejectionStoresTheRejectedStateAndOneDelivery(): void
    {
        $transaction = SimulatorTransactions::card($this->entityManager);

        $outcome = $this->decide($transaction->id, Provider::DemoCard, false);

        self::assertEquals(new DecisionOutcome(true, true, 'https://shop.test/checkout/confirmation?result=cancel'), $outcome);
        self::assertSame(['rejected', true], $this->state($transaction->id));
        self::assertEquals([new DeliverSimulatedWebhook($transaction->id)], $this->queuedDeliveries());
    }

    public function testTheStateAndTheDeliveryAreRolledBackTogether(): void
    {
        $transaction = SimulatorTransactions::transfer($this->entityManager);

        $failure = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($transaction): void {
                $this->decide($transaction->id, Provider::DemoTransfer, true);

                throw new \RuntimeException('Failure before commit.');
            });
        } catch (\RuntimeException $e) {
            $failure = $e;
        }

        self::assertSame('Failure before commit.', $failure->getMessage());

        self::assertSame(['open', false], $this->state($transaction->id));
        self::assertSame(0, $this->rows('payment_simulator'));
    }

    public function testASecondDecisionChangesNothingAndStoresNoSecondDelivery(): void
    {
        $transaction = SimulatorTransactions::transfer($this->entityManager);
        $this->decide($transaction->id, Provider::DemoTransfer, true);

        $second = $this->decide($transaction->id, Provider::DemoTransfer, false);

        self::assertEquals(new DecisionOutcome(true, false, 'https://shop.test/checkout/confirmation'), $second);
        self::assertSame(['paid', true], $this->state($transaction->id));
        self::assertSame(1, $this->rows('payment_simulator'));
    }

    public function testACancelledAndAnExpiredTransactionAreNotDecidedAndStoreNoDelivery(): void
    {
        $cancelled = SimulatorTransactions::card($this->entityManager);
        $this->cancel($cancelled->id, Provider::DemoCard);
        $expired = SimulatorTransactions::transfer($this->entityManager, expiresAt: '-1 second');

        self::assertFalse($this->decide($cancelled->id, Provider::DemoCard, true)->applied);
        self::assertFalse($this->decide($expired->id, Provider::DemoTransfer, true)->applied);

        self::assertSame(['cancelled', false], $this->state($cancelled->id));
        self::assertSame(['open', false], $this->state($expired->id));
        self::assertSame(0, $this->rows('payment_simulator'));
    }

    public function testAnUnknownTransactionAndAnotherProvidersTransactionAreNotFound(): void
    {
        $transaction = SimulatorTransactions::transfer($this->entityManager);

        $unknown = $this->decide('7d0e2a5e-0000-4000-8000-000000000000', Provider::DemoTransfer, true);
        $otherProvider = $this->decide($transaction->id, Provider::DemoCard, true);

        self::assertFalse($unknown->found);
        self::assertFalse($otherProvider->found);
        self::assertSame(['open', false], $this->state($transaction->id));
        self::assertSame(0, $this->rows('payment_simulator'));
        self::assertFalse($this->cancel($transaction->id, Provider::DemoCard)->found);
        self::assertFalse($this->cancel('7d0e2a5e-0000-4000-8000-000000000000', Provider::DemoTransfer)->found);
    }

    public function testACancelledTransactionStaysCancelledAndCancelsAgain(): void
    {
        $transaction = SimulatorTransactions::card($this->entityManager);

        self::assertEquals(new CancelOutcome(true, CancelResult::Cancelled), $this->cancel($transaction->id, Provider::DemoCard));
        self::assertEquals(new CancelOutcome(true, CancelResult::Cancelled), $this->cancel($transaction->id, Provider::DemoCard));

        self::assertSame(['cancelled', false], $this->state($transaction->id));
        self::assertSame(0, $this->rows('payment_simulator'));
    }

    public function testADecidedTransactionIsNotCancelled(): void
    {
        $paid = SimulatorTransactions::transfer($this->entityManager);
        $rejected = SimulatorTransactions::transfer($this->entityManager);
        $this->decide($paid->id, Provider::DemoTransfer, true);
        $this->decide($rejected->id, Provider::DemoTransfer, false);

        self::assertEquals(new CancelOutcome(true, CancelResult::Paid), $this->cancel($paid->id, Provider::DemoTransfer));
        self::assertEquals(new CancelOutcome(true, CancelResult::Rejected), $this->cancel($rejected->id, Provider::DemoTransfer));

        self::assertSame(['paid', true], $this->state($paid->id));
        self::assertSame(['rejected', true], $this->state($rejected->id));
    }

    private function decide(string $id, Provider $provider, bool $pay): DecisionOutcome
    {
        $outcome = $this->dispatch(new DecideTransaction($id, $provider, $pay));
        self::assertInstanceOf(DecisionOutcome::class, $outcome);

        return $outcome;
    }

    private function cancel(string $id, Provider $provider): CancelOutcome
    {
        $outcome = $this->dispatch(new CancelTransaction($id, $provider));
        self::assertInstanceOf(CancelOutcome::class, $outcome);

        return $outcome;
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * Read in SQL, past the identity map of the EntityManager.
     *
     * @return array{string, bool} status, whether decided_at is set
     */
    private function state(string $id): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, decided_at IS NOT NULL AS decided FROM payment_simulator_transaction WHERE id = ?', [$id]);
        self::assertIsArray($row);

        self::assertIsString($row['status']);

        return [$row['status'], (bool) $row['decided']];
    }

    /**
     * @return list<object>
     */
    private function queuedDeliveries(): array
    {
        $transport = $this->transport('payment_simulator');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        return array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), [...$transport->all()]));
    }
}
