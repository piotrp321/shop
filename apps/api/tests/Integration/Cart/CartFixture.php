<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Cart rows written as plain SQL on the given connection (the test's own, rolled back, or one that commits), for
 * states the commands cannot reach directly (expired, merged).
 */
final class CartFixture
{
    /**
     * @param list<array{string, int}> $lines variant id and quantity, in the order they were added
     *
     * @return string the cart id
     */
    public static function cart(Connection $connection, ?string $customerId = null, string $expiresAt = '+30 days', string $status = 'open', array $lines = []): string
    {
        $id = Uuid::v4()->toRfc4122();
        $connection->executeStatement(
            'INSERT INTO cart_cart (id, owner_type, customer_id, status, merged_into, merged_at, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, now(), now(), ?)',
            [
                $id,
                null === $customerId ? 'guest' : 'customer',
                $customerId,
                $status,
                'merged' === $status ? Uuid::v4()->toRfc4122() : null,
                'merged' === $status ? new \DateTimeImmutable()->format('c') : null,
                new \DateTimeImmutable($expiresAt)->format('c'),
            ],
        );
        foreach ($lines as $index => [$variantId, $quantity]) {
            self::line($connection, $id, $variantId, $quantity, new \DateTimeImmutable(\sprintf('-%d minutes', 100 - $index)));
        }

        return $id;
    }

    public static function line(Connection $connection, string $cartId, string $variantId, int $quantity, ?\DateTimeImmutable $addedAt = null): void
    {
        $connection->executeStatement(
            'INSERT INTO cart_line (id, cart_id, variant_id, quantity, added_at) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $cartId, $variantId, $quantity, ($addedAt ?? new \DateTimeImmutable())->format('c')],
        );
    }

    /**
     * @param list<string> $cartIds
     * @param list<string> $customerIds
     */
    public static function delete(Connection $connection, array $cartIds, array $customerIds = []): void
    {
        if ([] !== $cartIds) {
            $connection->executeStatement('DELETE FROM cart_cart WHERE id IN (?)', [$cartIds], [ArrayParameterType::STRING]);
        }
        if ([] !== $customerIds) {
            $connection->executeStatement('DELETE FROM cart_cart WHERE customer_id IN (?)', [$customerIds], [ArrayParameterType::STRING]);
        }
    }

    /**
     * @return list<array{string, int}> variant id and quantity, in the order of the lines
     */
    public static function lines(Connection $connection, string $cartId): array
    {
        $rows = $connection->fetchAllNumeric('SELECT variant_id, quantity FROM cart_line WHERE cart_id = ? ORDER BY added_at, id', [$cartId]);

        return array_map(static function (array $row): array {
            \assert(\is_string($row[0]) && \is_int($row[1]));

            return [$row[0], $row[1]];
        }, $rows);
    }
}
