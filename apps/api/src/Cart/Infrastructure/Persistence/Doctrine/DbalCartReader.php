<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence\Doctrine;

use App\Cart\Application\Port\CartReader;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use Doctrine\DBAL\Connection;

/**
 * Reads a cart with its lines in one statement straight on the connection: no lock, no transaction, nothing taken
 * from or left in the identity map.
 */
final readonly class DbalCartReader implements CartReader
{
    private const string SQL = <<<'SQL'
        SELECT c.id, c.owner_type, c.customer_id, c.status, c.merged_into, c.merged_at, c.created_at, c.updated_at,
               c.expires_at, l.variant_id, l.quantity, l.added_at
        FROM cart_cart c
        LEFT JOIN cart_line l ON l.cart_id = c.id
        WHERE {condition}
        ORDER BY l.added_at, l.id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function find(CartId $id): ?Cart
    {
        return $this->read('c.id = CAST(:value AS uuid)', $id->toString());
    }

    public function findForCustomer(string $customerId): ?Cart
    {
        return $this->read('c.customer_id = :value', $customerId);
    }

    /**
     * @param string $condition one of the two constant conditions above, never input
     */
    private function read(string $condition, string $value): ?Cart
    {
        $rows = $this->connection->fetchAllAssociative(str_replace('{condition}', $condition, self::SQL), ['value' => $value]);
        if ([] === $rows) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            if (null === $row['variant_id']) {
                continue;
            }
            \assert(\is_string($row['variant_id']) && \is_int($row['quantity']) && \is_string($row['added_at']));
            $lines[] = new CartLine(VariantId::fromString($row['variant_id']), Quantity::of($row['quantity']), new \DateTimeImmutable($row['added_at']));
        }

        $cart = $rows[0];
        \assert(\is_string($cart['id']) && \is_string($cart['owner_type']) && \is_string($cart['status']));
        \assert(\is_string($cart['created_at']) && \is_string($cart['updated_at']) && \is_string($cart['expires_at']));
        \assert((null === $cart['customer_id'] || \is_string($cart['customer_id'])) && (null === $cart['merged_into'] || \is_string($cart['merged_into'])));
        \assert(null === $cart['merged_at'] || \is_string($cart['merged_at']));

        return Cart::reconstitute(
            CartId::fromString($cart['id']),
            DoctrineCartRepository::CUSTOMER === $cart['owner_type'] ? CartOwner::customer($cart['customer_id'] ?? '') : CartOwner::guest(),
            CartStatus::from($cart['status']),
            null === $cart['merged_into'] ? null : CartId::fromString($cart['merged_into']),
            null === $cart['merged_at'] ? null : new \DateTimeImmutable($cart['merged_at']),
            $lines,
            new \DateTimeImmutable($cart['created_at']),
            new \DateTimeImmutable($cart['updated_at']),
            new \DateTimeImmutable($cart['expires_at']),
        );
    }
}
