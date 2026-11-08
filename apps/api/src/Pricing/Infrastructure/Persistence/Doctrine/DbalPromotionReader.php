<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\ListedPromotion;
use App\Pricing\Application\Port\PromotionReader;
use App\Pricing\Application\Query\PromotionCursor;
use App\Pricing\Domain\Promotion\PromotionStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The list of promotions straight from the tables, along the index (starts_at DESC, id) with a keyset cursor; the
 * status is a condition on the period at now. The variants are counted per promotion of the page.
 */
final readonly class DbalPromotionReader implements PromotionReader
{
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function page(?PromotionStatus $status, \DateTimeImmutable $now, ?PromotionCursor $after, int $limit): array
    {
        $conditions = [];
        $parameters = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $status) {
            $conditions[] = match ($status) {
                PromotionStatus::Planned => 'p.starts_at > CAST(:now AS timestamptz)',
                PromotionStatus::Active => 'p.starts_at <= CAST(:now AS timestamptz) AND p.ends_at > CAST(:now AS timestamptz)',
                PromotionStatus::Ended => 'p.ends_at <= CAST(:now AS timestamptz)',
            };
            $parameters['now'] = $now->format(self::MOMENT);
        }
        if (null !== $after) {
            $conditions[] = '(p.starts_at < CAST(:afterStartsAt AS timestamptz) OR (p.starts_at = CAST(:afterStartsAt AS timestamptz) AND p.id > CAST(:afterId AS uuid)))';
            $parameters['afterStartsAt'] = $after->startsAt->format(self::MOMENT);
            $parameters['afterId'] = $after->id;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, p.percent_off, p.starts_at, p.ends_at, p.version, '
            .'(SELECT count(*) FROM pricing_promotion_variant pv WHERE pv.promotion_id = p.id) AS variant_count '
            .'FROM pricing_promotion p'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .' ORDER BY p.starts_at DESC, p.id LIMIT :limit',
            $parameters,
            $types,
        );

        return array_map(static function (array $row): ListedPromotion {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['starts_at']) && \is_string($row['ends_at']));
            \assert(\is_int($row['percent_off']) && \is_int($row['version']) && \is_int($row['variant_count']));
            $utc = new \DateTimeZone('UTC');

            return new ListedPromotion(
                $row['id'],
                $row['name'],
                $row['percent_off'],
                new \DateTimeImmutable($row['starts_at'])->setTimezone($utc),
                new \DateTimeImmutable($row['ends_at'])->setTimezone($utc),
                $row['variant_count'],
                $row['version'],
            );
        }, $rows);
    }
}
