<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\PriceHistoryReader;
use App\Pricing\Application\Query\PriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use App\Pricing\Domain\Price\ProductId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads straight on the connection, one statement per page. The index (product_id, changed_at DESC, id) serves the
 * product and the time order; the id is descending, so the later of two changes within one second comes first (the
 * ids are time-ordered, see DoctrineProductPriceRepository), which the database finishes by sorting the few rows of
 * one second. The cursor is a row comparison of the same two columns in the same direction. Nothing here writes.
 */
final readonly class DbalPriceHistoryReader implements PriceHistoryReader
{
    private const string COLUMNS = 'id, changed_at, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, source, actor_name';

    public function __construct(private Connection $connection)
    {
    }

    public function page(ProductId $productId, ?PriceHistoryCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }

        $sql = 'SELECT '.self::COLUMNS.' FROM pricing_price_history WHERE product_id = CAST(:product AS uuid)';
        $parameters = ['product' => $productId->toString(), 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $after) {
            $sql .= ' AND (changed_at, id) < (CAST(:after_time AS timestamptz), CAST(:after_id AS uuid))';
            $parameters['after_time'] = $after->changedAt->format('Y-m-d H:i:s.uP');
            $parameters['after_id'] = $after->id;
        }
        $rows = $this->connection->fetchAllAssociative($sql.' ORDER BY changed_at DESC, id DESC LIMIT :limit', $parameters, $types);

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row the columns of self::COLUMNS
     */
    private function fromRow(array $row): PriceHistoryItem
    {
        \assert(\is_string($row['id']) && \is_string($row['changed_at']) && \is_string($row['currency']) && \is_string($row['tax_class']) && \is_string($row['source']));
        \assert(\is_int($row['gross_amount']) && \is_int($row['net_amount']) && \is_int($row['vat_amount']) && \is_int($row['vat_rate_bp']));
        \assert(null === $row['actor_name'] || \is_string($row['actor_name']));

        return new PriceHistoryItem(
            $row['id'],
            new \DateTimeImmutable($row['changed_at']),
            $row['currency'],
            $row['gross_amount'],
            $row['net_amount'],
            $row['vat_amount'],
            $row['vat_rate_bp'],
            $row['tax_class'],
            $row['source'],
            $row['actor_name'],
        );
    }
}
