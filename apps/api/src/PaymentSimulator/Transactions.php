<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class Transactions
{
    /** A transaction id is a UUID; anything else is unknown without asking the database (which rejects it). */
    public static function isId(string $id): bool
    {
        return 1 === preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id);
    }

    /**
     * The transaction of the provider, read under the lock of its row (`SELECT … FOR UPDATE` through the ORM) in the
     * open transaction, or null when there is none. A transaction already in the identity map is read again under the
     * lock: `find()` would return its old state without asking the database.
     */
    public static function lock(EntityManagerInterface $entityManager, string $id, Provider $provider): ?Transaction
    {
        if (!self::isId($id)) {
            return null;
        }
        $known = false !== $entityManager->getUnitOfWork()->tryGetById($id, Transaction::class);
        $transaction = $entityManager->find(Transaction::class, $id, LockMode::PESSIMISTIC_WRITE);
        if ($known && $transaction instanceof Transaction) {
            $entityManager->refresh($transaction, LockMode::PESSIMISTIC_WRITE);
        }

        return $transaction instanceof Transaction && $transaction->provider === $provider ? $transaction : null;
    }
}
