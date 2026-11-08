<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\DiscountCodeRecord;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\DiscountCodeUsageRecord;
use Doctrine\ORM\EntityManagerInterface;

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

    public function activeUsesOf(DiscountCodeId $codeId): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(self::ACTIVE_USES, ['codeId' => $codeId->toString()]);
        \assert(\is_int($count));

        return $count;
    }
}
