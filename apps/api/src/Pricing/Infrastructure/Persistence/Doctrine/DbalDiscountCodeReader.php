<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\DiscountCodeReader;
use App\Pricing\Application\Port\ListedDiscountCode;
use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The list of discount codes straight from the tables, along the index (created_at DESC, id) with a keyset cursor;
 * the status is a condition on the flag and the period at now, the prefix a LIKE on the stored (upper-case) text. The
 * active usages are counted per code of the page along their partial index.
 */
final readonly class DbalDiscountCodeReader implements DiscountCodeReader
{
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function page(?DiscountCodeStatus $status, ?string $codePrefix, \DateTimeImmutable $now, ?DiscountCodeCursor $after, int $limit): array
    {
        $conditions = [];
        $parameters = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $status) {
            $conditions[] = match ($status) {
                DiscountCodeStatus::Disabled => 'NOT c.enabled',
                DiscountCodeStatus::Planned => 'c.enabled AND c.starts_at > CAST(:now AS timestamptz)',
                DiscountCodeStatus::Active => 'c.enabled AND c.starts_at <= CAST(:now AS timestamptz) AND (c.ends_at IS NULL OR c.ends_at > CAST(:now AS timestamptz))',
                DiscountCodeStatus::Expired => 'c.enabled AND c.ends_at <= CAST(:now AS timestamptz)',
            };
            if (DiscountCodeStatus::Disabled !== $status) {
                $parameters['now'] = $now->format(self::MOMENT);
            }
        }
        if (null !== $codePrefix) {
            // A–Z, 0–9 and the dash only (the caller's contract), none of them special in LIKE.
            if (1 !== preg_match('/^[A-Z0-9-]+$/D', $codePrefix)) {
                throw new \InvalidArgumentException('A code prefix has the characters of a code only.');
            }
            $conditions[] = 'c.code LIKE :prefix';
            $parameters['prefix'] = $codePrefix.'%';
        }
        if (null !== $after) {
            $conditions[] = '(c.created_at < CAST(:afterCreatedAt AS timestamptz) OR (c.created_at = CAST(:afterCreatedAt AS timestamptz) AND c.id > CAST(:afterId AS uuid)))';
            $parameters['afterCreatedAt'] = $after->createdAt->format(self::MOMENT);
            $parameters['afterId'] = $after->id;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.code, c.kind, c.percent_off, c.amount_gross, c.minimum_order_gross, c.starts_at, c.ends_at, c.usage_limit, c.enabled, c.version, c.created_at, '
            .'(SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id = c.id AND u.released_at IS NULL) AS active_uses '
            .'FROM pricing_discount_code c'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .' ORDER BY c.created_at DESC, c.id LIMIT :limit',
            $parameters,
            $types,
        );

        return array_map(static function (array $row): ListedDiscountCode {
            \assert(\is_string($row['id']) && \is_string($row['code']) && \is_string($row['kind']) && \is_string($row['starts_at']) && \is_string($row['created_at']));
            \assert((null === $row['ends_at'] || \is_string($row['ends_at'])) && \is_bool($row['enabled']) && \is_int($row['version']) && \is_int($row['active_uses']));
            \assert((null === $row['percent_off'] || \is_int($row['percent_off'])) && (null === $row['amount_gross'] || \is_int($row['amount_gross'])));
            \assert((null === $row['minimum_order_gross'] || \is_int($row['minimum_order_gross'])) && (null === $row['usage_limit'] || \is_int($row['usage_limit'])));
            $utc = new \DateTimeZone('UTC');

            return new ListedDiscountCode(
                $row['id'],
                $row['code'],
                DiscountKind::from($row['kind']),
                $row['percent_off'] ?? $row['amount_gross'] ?? throw new \UnexpectedValueException('A discount code without its value.'),
                $row['minimum_order_gross'],
                new \DateTimeImmutable($row['starts_at'])->setTimezone($utc),
                null === $row['ends_at'] ? null : new \DateTimeImmutable($row['ends_at'])->setTimezone($utc),
                $row['usage_limit'],
                $row['enabled'],
                $row['active_uses'],
                $row['version'],
                new \DateTimeImmutable($row['created_at'])->setTimezone($utc),
            );
        }, $rows);
    }
}
