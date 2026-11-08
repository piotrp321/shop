<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use App\Pricing\Domain\DiscountCode\OrderId;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\DiscountCodeRecord;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\DiscountCodeUsageRecord;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Usages through their records (the general rule). The active usages are counted by one statement on the connection,
 * along the partial index of the active usages, so the count is what is stored, not what the identity map holds.
 */
final readonly class DoctrineDiscountCodeUsageRepository implements DiscountCodeUsageRepository
{
    /** The count of the active usages of a code (public for the test of its plan). */
    public const string ACTIVE_USES = 'SELECT count(*) FROM pricing_discount_code_usage WHERE code_id = CAST(:codeId AS uuid) AND released_at IS NULL';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(DiscountCodeUsage $usage): void
    {
        $this->entityManager->persist(new DiscountCodeUsageRecord(
            $usage->id()->toString(),
            $this->entityManager->getReference(DiscountCodeRecord::class, $usage->codeId()->toString()) ?? throw new \LogicException('A reference is always given.'),
            $usage->orderId()->toString(),
            $usage->usedAt(),
            $usage->releasedAt(),
        ));
        $this->entityManager->flush();
    }

    /**
     * Along the unique index of the order; a record already in the identity map is refreshed by the read.
     */
    public function ofOrder(OrderId $orderId): ?DiscountCodeUsage
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(DiscountCodeUsageRecord::class, 'u')
            ->where('u.orderId = :orderId')
            ->setParameter('orderId', $orderId->toString())
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $record || $record instanceof DiscountCodeUsageRecord);

        return null === $record ? null : DiscountCodeUsage::reconstitute(
            DiscountCodeUsageId::fromString($record->id),
            DiscountCodeId::fromString($record->code->id),
            OrderId::fromString($record->orderId),
            $record->usedAt,
            $record->releasedAt,
        );
    }

    /**
     * Only the release changes; the UPDATE does not touch the foreign key, so it takes no lock of the code.
     */
    public function save(DiscountCodeUsage $usage): void
    {
        $record = $this->entityManager->find(DiscountCodeUsageRecord::class, $usage->id()->toString()) ?? throw new \LogicException('Only a stored usage is saved.');
        $record->releasedAt = $usage->releasedAt();
        $this->entityManager->flush();
    }

    public function activeUsesOf(DiscountCodeId $codeId): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(self::ACTIVE_USES, ['codeId' => $codeId->toString()]);
        \assert(\is_int($count));

        return $count;
    }
}
