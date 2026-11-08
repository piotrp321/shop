<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeActor;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeModifiedConcurrently;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeTaken;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\DiscountCodeRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;

/**
 * Discount codes through their records (the general rule). The lock is a PESSIMISTIC_WRITE (SELECT … FOR UPDATE) of
 * the row through the ORM; a record already in the identity map is refreshed under the lock, so the code is always
 * read as stored, never as an earlier read of the same transaction saw it.
 */
final readonly class DoctrineDiscountCodeRepository implements DiscountCodeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Along the primary key; a record already in the identity map is refreshed by the read.
     */
    public function find(DiscountCodeId $id): ?DiscountCode
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(DiscountCodeRecord::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id->toString())
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $record || $record instanceof DiscountCodeRecord);

        return null === $record ? null : self::toDomain($record);
    }

    /**
     * Along the unique index of the text; a record already in the identity map is refreshed by the read.
     */
    public function findByCode(DiscountCodeValue $code): ?DiscountCode
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(DiscountCodeRecord::class, 'c')
            ->where('c.code = :code')
            ->setParameter('code', $code->toString())
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $record || $record instanceof DiscountCodeRecord);

        return null === $record ? null : self::toDomain($record);
    }

    public function lock(DiscountCodeId $id): ?DiscountCode
    {
        $managed = $this->entityManager->getUnitOfWork()->tryGetById($id->toString(), DiscountCodeRecord::class);
        if (!$managed instanceof DiscountCodeRecord) {
            $record = $this->entityManager->find(DiscountCodeRecord::class, $id->toString(), LockMode::PESSIMISTIC_WRITE);

            return null === $record ? null : self::toDomain($record);
        }

        // A refresh of a row that no longer exists keeps the stale record, so whether the row still exists is asked
        // first, under the same lock.
        $exists = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM pricing_discount_code WHERE id = CAST(:id AS uuid) FOR UPDATE', ['id' => $id->toString()]);
        if (false === $exists) {
            $this->entityManager->detach($managed);

            return null;
        }
        $this->entityManager->refresh($managed, LockMode::PESSIMISTIC_WRITE);

        return self::toDomain($managed);
    }

    /**
     * A code at version 1 is new; a revised one was read at its version − 1, which the versioned UPDATE checks. The
     * text never changes, so only a new code can meet the unique index of the text.
     */
    public function save(DiscountCode $code): void
    {
        $id = $code->id()->toString();
        if (1 === $code->version()) {
            $record = new DiscountCodeRecord($id);
            $record->code = $code->code()->toString();
            $record->createdAt = $code->createdAt();
            $record->createdById = $code->createdBy()?->id();
            $record->createdByName = $code->createdBy()?->name();
            $this->entityManager->persist($record);
        } else {
            $record = $this->entityManager->find(DiscountCodeRecord::class, $id) ?? throw new DiscountCodeModifiedConcurrently();
            // The versioned UPDATE compares with this value, so a record cached with a newer version cannot make a
            // stale write pass.
            $record->version = $code->version() - 1;
        }

        $value = $code->value();
        $record->kind = $value->kind()->value;
        $record->percentOff = $value->percentOff()?->value();
        $record->amountGross = $value->amountOff()?->amount();
        $record->minimumOrderGross = $code->minimumOrder()?->gross();
        $record->startsAt = $code->period()->startsAt();
        $record->endsAt = $code->period()->endsAt();
        $record->usageLimit = $code->usageLimit()?->value();
        $record->enabled = $code->isEnabled();
        $record->updatedAt = $code->updatedAt();
        $record->updatedById = $code->updatedBy()?->id();
        $record->updatedByName = $code->updatedBy()?->name();

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new DiscountCodeModifiedConcurrently();
        } catch (UniqueConstraintViolationException $violation) {
            if (str_contains($violation->getMessage(), DiscountCodeRecord::CODE_CONSTRAINT)) {
                throw new DiscountCodeTaken();
            }

            throw $violation;
        }
    }

    private static function toDomain(DiscountCodeRecord $record): DiscountCode
    {
        return DiscountCode::reconstitute(
            DiscountCodeId::fromString($record->id),
            DiscountCodeValue::of($record->code),
            DiscountValue::of(DiscountKind::from($record->kind), $record->percentOff ?? $record->amountGross ?? throw new \UnexpectedValueException('A discount code without its value.')),
            null === $record->minimumOrderGross ? null : MinimumOrder::of($record->minimumOrderGross),
            ValidityPeriod::of($record->startsAt, $record->endsAt),
            null === $record->usageLimit ? null : UsageLimit::of($record->usageLimit),
            $record->enabled,
            $record->createdAt,
            self::actor($record->createdById, $record->createdByName),
            $record->updatedAt,
            self::actor($record->updatedById, $record->updatedByName),
            $record->version,
        );
    }

    private static function actor(?string $id, ?string $name): ?DiscountCodeActor
    {
        return null === $id || null === $name ? null : DiscountCodeActor::staff($id, $name);
    }
}
