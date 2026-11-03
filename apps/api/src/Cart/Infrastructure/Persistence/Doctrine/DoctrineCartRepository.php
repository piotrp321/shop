<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence\Doctrine;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\Quantity;
use App\Cart\Infrastructure\Persistence\Doctrine\Record\CartLineRecord;
use App\Cart\Infrastructure\Persistence\Doctrine\Record\CartRecord;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Maps the Cart aggregate to and from its records, inside the transaction of command.bus.
 *
 * Every lock is a PESSIMISTIC_WRITE (SELECT … FOR UPDATE) of the cart row through the ORM, and the cart is read once
 * the lock is held. A record already in the identity map is refreshed under the lock, and so are its lines, after
 * the lock: Doctrine's own cascade of refresh would read (and lock) the lines before the cart row, against the order
 * of every other transaction. A customer cart is created with INSERT … ON CONFLICT DO NOTHING before the lock, so two
 * first changes of one customer end with one row and no unique violation (which would close the entity manager).
 */
final readonly class DoctrineCartRepository implements CartRepository
{
    /** Values of cart_cart.owner_type. */
    public const string GUEST = 'guest';
    public const string CUSTOMER = 'customer';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function lock(CartId $id): ?Cart
    {
        $record = $this->lockRecord($id->toString());

        return null === $record ? null : self::toDomain($record);
    }

    public function lockForCustomer(string $customerId): ?Cart
    {
        $id = $this->customerCartId($customerId);

        return null === $id ? null : $this->lock(CartId::fromString($id));
    }

    /**
     * PostgreSQL makes an insert of a customer id that another, uncommitted transaction inserted wait for it; once
     * that one ends, the insert either adds the row (the other rolled back) or skips it, and the lock reads the row
     * as the other committed it.
     */
    public function lockOrCreateForCustomer(string $customerId, \DateTimeImmutable $now): Cart
    {
        $new = Cart::createForCustomer(CartId::generate(), $customerId, $now);
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO cart_cart (id, owner_type, customer_id, status, created_at, updated_at, expires_at) '
            .'VALUES (:id, :owner_type, :customer_id, :status, :created_at, :updated_at, :expires_at) '
            .'ON CONFLICT (customer_id) WHERE customer_id IS NOT NULL DO NOTHING',
            [
                'id' => $new->id()->toString(),
                'owner_type' => self::CUSTOMER,
                'customer_id' => $customerId,
                'status' => $new->status()->value,
                'created_at' => $new->createdAt(),
                'updated_at' => $new->updatedAt(),
                'expires_at' => $new->expiresAt(),
            ],
            ['created_at' => Types::DATETIMETZ_IMMUTABLE, 'updated_at' => Types::DATETIMETZ_IMMUTABLE, 'expires_at' => Types::DATETIMETZ_IMMUTABLE],
        );

        return $this->lockForCustomer($customerId) ?? throw new \LogicException('A customer cart just created or found is not stored.');
    }

    public function add(Cart $cart): void
    {
        $record = new CartRecord($cart->id()->toString());
        $this->write($cart, $record);
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    /**
     * The lines are matched by product: a line of a product that stays is updated in place, so a line removed and
     * one of the same product added in one change never meet the unique index (Doctrine deletes after it inserts).
     */
    public function save(Cart $cart): void
    {
        $record = $this->entityManager->getUnitOfWork()->tryGetById($cart->id()->toString(), CartRecord::class);
        if (!$record instanceof CartRecord) {
            throw new \LogicException('A cart is saved only after it was locked.');
        }

        $this->write($cart, $record);
        $this->entityManager->flush();
    }

    private function write(Cart $cart, CartRecord $record): void
    {
        $record->ownerType = $cart->owner()->isGuest() ? self::GUEST : self::CUSTOMER;
        $record->customerId = $cart->owner()->customerId();
        $record->status = $cart->status()->value;
        $record->mergedInto = $cart->mergedInto()?->toString();
        $record->mergedAt = $cart->mergedAt();
        $record->createdAt = $cart->createdAt();
        $record->updatedAt = $cart->updatedAt();
        $record->expiresAt = $cart->expiresAt();

        $stored = [];
        foreach ($record->lines as $line) {
            $stored[$line->productId] = $line;
        }
        foreach ($cart->lines() as $line) {
            $productId = $line->productId()->toString();
            $lineRecord = $stored[$productId] ?? null;
            if (null === $lineRecord) {
                // Time-ordered, so lines added in the same second keep their order.
                $lineRecord = new CartLineRecord(Uuid::v7()->toRfc4122(), $record, $productId);
                $record->lines->add($lineRecord);
            }
            $lineRecord->quantity = $line->quantity()->value();
            $lineRecord->addedAt = $line->addedAt();
            unset($stored[$productId]);
        }
        foreach ($stored as $removed) {
            $record->lines->removeElement($removed);
        }
    }

    private function lockRecord(string $id): ?CartRecord
    {
        $managed = $this->entityManager->getUnitOfWork()->tryGetById($id, CartRecord::class);
        if (!$managed instanceof CartRecord) {
            return $this->entityManager->find(CartRecord::class, $id, LockMode::PESSIMISTIC_WRITE);
        }

        // A refresh of a row that no longer exists keeps the stale record, so whether the row still exists is
        // asked first, under the same lock.
        $exists = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM cart_cart WHERE id = CAST(:id AS uuid) FOR UPDATE', ['id' => $id]);
        if (false === $exists) {
            foreach ($managed->lines as $line) {
                $this->entityManager->detach($line);
            }
            $this->entityManager->detach($managed);

            return null;
        }

        // The refresh gives the record a new, uninitialized collection of lines, loaded from the rows as they are
        // now; a line record already in the identity map would keep its old quantity, so each is refreshed too.
        $this->entityManager->refresh($managed, LockMode::PESSIMISTIC_WRITE);
        foreach ($managed->lines as $line) {
            $this->entityManager->refresh($line);
        }

        return $managed;
    }

    private function customerCartId(string $customerId): ?string
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT id FROM cart_cart WHERE customer_id = :customer_id', ['customer_id' => $customerId]);
        \assert(false === $id || \is_string($id));

        return false === $id ? null : $id;
    }

    private static function toDomain(CartRecord $record): Cart
    {
        $lines = [];
        foreach ($record->lines as $line) {
            $lines[] = new CartLine(ProductId::fromString($line->productId), Quantity::of($line->quantity), $line->addedAt);
        }

        return Cart::reconstitute(
            CartId::fromString($record->id),
            self::CUSTOMER === $record->ownerType ? CartOwner::customer($record->customerId ?? '') : CartOwner::guest(),
            CartStatus::from($record->status),
            null === $record->mergedInto ? null : CartId::fromString($record->mergedInto),
            $record->mergedAt,
            $lines,
            $record->createdAt,
            $record->updatedAt,
            $record->expiresAt,
        );
    }
}
