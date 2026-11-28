<?php

declare(strict_types=1);

namespace App\Delivery\Infrastructure\Persistence\Doctrine;

use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodModifiedConcurrently;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryMethodNameTaken;
use App\Delivery\Domain\DeliveryMethodRepository;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\TrackingUrlTemplate;
use App\Delivery\Infrastructure\Persistence\Doctrine\Record\DeliveryMethodRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;

/**
 * Delivery methods through their records (the general rule), without locks (A8): a change is checked by the versioned
 * UPDATE. Every read refreshes a record already in the identity map, so a method is always read as stored, never as an
 * earlier read of the same transaction saw it. The texts are read as stored, without the rule of texts, so a method
 * stored before a stricter rule is still listed, shown and offered (spec api-input-contract, E7).
 */
final readonly class DoctrineDeliveryMethodRepository implements DeliveryMethodRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(DeliveryMethodId $id): ?DeliveryMethod
    {
        $records = $this->records('m.id = :id', ['id' => $id->toString()]);

        return [] === $records ? null : self::toDomain($records[0]);
    }

    public function all(): array
    {
        return array_map(self::toDomain(...), $this->records(null, []));
    }

    /**
     * Along the index (active, position).
     */
    public function active(): array
    {
        return array_map(self::toDomain(...), $this->records('m.active = true', []));
    }

    public function countActive(): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(DeliveryMethodRecord::class, 'm')
            ->where('m.active = true')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * A method at version 1 is new; a revised one was read at its version − 1, which the versioned UPDATE checks. The
     * unique index of the lower-case name refuses a name taken in another case, by a new method or by a renamed one.
     */
    public function save(DeliveryMethod $method): void
    {
        $id = $method->id()->toString();
        if (1 === $method->version()) {
            $record = new DeliveryMethodRecord($id);
            $record->createdAt = $method->createdAt();
            $this->entityManager->persist($record);
        } else {
            $record = $this->entityManager->find(DeliveryMethodRecord::class, $id) ?? throw new DeliveryMethodModifiedConcurrently();
            // The versioned UPDATE compares with this value, so a record cached with a newer version cannot make a
            // stale write pass.
            $record->version = $method->version() - 1;
        }

        $record->name = $method->name()->toString();
        $record->description = $method->description()->toString();
        $record->priceGross = $method->price()->gross();
        $record->freeFromGross = $method->freeFrom()?->gross();
        $record->active = $method->isActive();
        $record->position = $method->position()->value();
        $record->trackingUrlTemplate = $method->trackingUrlTemplate()?->toString();
        $record->updatedAt = $method->updatedAt();

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new DeliveryMethodModifiedConcurrently();
        } catch (UniqueConstraintViolationException $violation) {
            if (str_contains($violation->getMessage(), DeliveryMethodRecord::NAME_CONSTRAINT)) {
                throw new DeliveryMethodNameTaken();
            }

            throw $violation;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<DeliveryMethodRecord>
     */
    private function records(?string $condition, array $parameters): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(DeliveryMethodRecord::class, 'm');
        if (null !== $condition) {
            $builder->where($condition);
        }
        foreach ($parameters as $name => $value) {
            $builder->setParameter($name, $value);
        }

        /** @var list<DeliveryMethodRecord> */
        return $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
    }

    private static function toDomain(DeliveryMethodRecord $record): DeliveryMethod
    {
        return DeliveryMethod::reconstitute(
            DeliveryMethodId::fromString($record->id),
            DeliveryMethodName::stored($record->name),
            DeliveryMethodDescription::stored($record->description),
            DeliveryPrice::of($record->priceGross),
            FreeDeliveryThreshold::optional($record->freeFromGross),
            $record->active,
            DeliveryPosition::of($record->position),
            TrackingUrlTemplate::optional($record->trackingUrlTemplate),
            $record->createdAt,
            $record->updatedAt,
            $record->version,
        );
    }
}
