<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Cart aggregate; only DoctrineCartRepository maps it to and from the domain, and
 * DbalCartReader reads its rows. One cart per customer (the partial unique index); the migration adds the CHECK
 * constraints the mapping cannot express: a customer id exactly for a customer cart, and only a guest cart merged.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_cart')]
// The predicate as PostgreSQL gives it back (pg_get_expr), so the schema comparison sees no difference.
#[ORM\UniqueConstraint(name: self::CUSTOMER_INDEX, columns: ['customer_id'], options: ['where' => '(customer_id IS NOT NULL)'])]
#[ORM\Index(name: 'cart_cart_expires_at_idx', columns: ['expires_at'])]
class CartRecord
{
    public const string CUSTOMER_INDEX = 'cart_cart_customer_id_key';

    #[ORM\Column(length: 16)]
    public string $ownerType;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $customerId = null;

    #[ORM\Column(length: 16)]
    public string $status;

    #[ORM\Column(type: 'guid', nullable: true)]
    public ?string $mergedInto = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $mergedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $expiresAt;

    /** @var Collection<int, CartLineRecord> in the order the lines were added */
    #[ORM\OneToMany(targetEntity: CartLineRecord::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['addedAt' => 'ASC', 'id' => 'ASC'])]
    public Collection $lines;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
        $this->lines = new ArrayCollection();
    }
}
