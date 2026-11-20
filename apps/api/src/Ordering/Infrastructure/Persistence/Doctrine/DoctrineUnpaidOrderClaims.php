<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\UnpaidOrderClaims;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\UnpaidOrderIdentity;
use App\Ordering\Infrastructure\Persistence\Doctrine\Record\OrderRecord;
use App\Ordering\Infrastructure\Persistence\Doctrine\Record\UnpaidOrderClaimRecord;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The claims of unpaid orders through their record, in the transaction of the command (PlaceOrder, MarkOrderPaid,
 * ExpireUnpaidOrder). Every write flushes at once; a count is a DQL query, so it reads what others committed (under
 * the locks of PostgresUnpaidOrderLock, the committed claims of the same address and identity).
 */
final readonly class DoctrineUnpaidOrderClaims implements UnpaidOrderClaims
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countByAddress(string $address, \DateTimeImmutable $now): int
    {
        return $this->count('addressKey', $address, $now);
    }

    public function countByIdentity(UnpaidOrderIdentity $identity, \DateTimeImmutable $now): int
    {
        return $this->count('identityKey', $identity->key(), $now);
    }

    public function add(OrderId $orderId, string $address, UnpaidOrderIdentity $identity, \DateTimeImmutable $expiresAt): void
    {
        // The order was stored just before (PlaceOrder): a reference is enough for the foreign key.
        $order = $this->entityManager->getReference(OrderRecord::class, $orderId->toString()) ?? throw new \LogicException('No reference to the order.');
        $this->entityManager->persist(new UnpaidOrderClaimRecord($order, $address, $identity->key(), $expiresAt));
        $this->entityManager->flush();
    }

    public function remove(OrderId $orderId): void
    {
        $record = $this->entityManager->find(UnpaidOrderClaimRecord::class, $orderId->toString());
        if (null === $record) {
            return;
        }
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    private function count(string $field, string $key, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.order)')
            ->from(UnpaidOrderClaimRecord::class, 'c')
            ->where('c.'.$field.' = :key')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('key', $key)
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
