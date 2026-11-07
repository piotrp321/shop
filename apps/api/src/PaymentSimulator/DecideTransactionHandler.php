<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Decides a transaction under the lock of its row, so a decision and a cancellation of the same transaction run one
 * after the other. An applied decision sends DeliverSimulatedWebhook in the same transaction (the transport is
 * a table on this connection).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DecideTransactionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DecideTransaction $command): DecisionOutcome
    {
        $transaction = Transactions::lock($this->entityManager, $command->transactionId, $command->provider);
        if (null === $transaction) {
            return new DecisionOutcome(false, false);
        }

        $applied = $transaction->decide($command->pay, \DateTimeImmutable::createFromInterface($this->clock->now()));
        if ($applied) {
            $this->bus->dispatch(new DeliverSimulatedWebhook($transaction->id));
        }

        return new DecisionOutcome(true, $applied, $transaction->returnUrl());
    }
}
