<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cancels a transaction under the lock of its row (see {@see DecideTransactionHandler}): an open transaction is
 * cancelled and can no longer be paid, a decided one stays as it is.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class CancelTransactionHandler
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(CancelTransaction $command): CancelOutcome
    {
        $transaction = Transactions::lock($this->entityManager, $command->transactionId, $command->provider);

        return null === $transaction ? new CancelOutcome(false) : new CancelOutcome(true, $transaction->cancel());
    }
}
