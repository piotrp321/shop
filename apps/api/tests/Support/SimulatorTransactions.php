<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\PaymentSimulator\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Transactions of the payment simulator for the tests, stored through the EntityManager (and flushed).
 */
final class SimulatorTransactions
{
    public static function transfer(EntityManagerInterface $entityManager, string $reference = 'R-100001', int $amount = 12_345, string $expiresAt = '+30 minutes', ?string $id = null): Transaction
    {
        $transaction = Transaction::openTransfer(
            $id ?? Uuid::v4()->toRfc4122(),
            $reference,
            $amount,
            'PLN',
            'https://shop.test/checkout/confirmation',
            new \DateTimeImmutable($expiresAt),
            new \DateTimeImmutable(),
        );
        $entityManager->persist($transaction);
        $entityManager->flush();

        return $transaction;
    }

    public static function card(EntityManagerInterface $entityManager, string $reference = 'R-100001', int $amount = 12_345, string $expiresAt = '+30 minutes', ?string $id = null): Transaction
    {
        $transaction = Transaction::openCard(
            $id ?? Uuid::v4()->toRfc4122(),
            $reference,
            $amount,
            'PLN',
            'https://shop.test/checkout/confirmation?result=success',
            'https://shop.test/checkout/confirmation?result=cancel',
            new \DateTimeImmutable($expiresAt),
            new \DateTimeImmutable(),
        );
        $entityManager->persist($transaction);
        $entityManager->flush();

        return $transaction;
    }
}
