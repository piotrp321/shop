<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\ListedOrder;

/**
 * The position after an order in a sort of the order list of the panel: (placed_at, id) in either direction,
 * (total_gross, id) in either direction, or (refund_due_at, id) nearest first for the pending refunds. Handed out as
 * opaque text, base64url without padding of a JSON object `{"s": the key of the sort, "v": moment or amount, "i": id}`,
 * so the order can change without changing the API; a cursor of one sort is no cursor of another. The history of a
 * customer pages by placement, newest first, with the same cursor.
 */
final readonly class BackofficeOrderCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    /** UTC with microseconds, so a cursor keeps the whole precision of the stored moment. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';
    /** encode() writes UTC only; any other offset is not a cursor (and PostgreSQL rejects offsets beyond +-15:59). */
    private const string TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/D';
    /** The ids of orders as stored: lower-case UUIDs. */
    private const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * @param \DateTimeImmutable|int $value the moment of placement or of the refund due date, or the amount in grosze
     */
    private function __construct(
        public BackofficeOrderSort $sort,
        public \DateTimeImmutable|int $value,
        public string $id,
    ) {
    }

    /**
     * @throws \LogicException the order has no refund to sort by
     */
    public static function after(ListedOrder $order, BackofficeOrderSort $sort): self
    {
        $value = match ($sort) {
            BackofficeOrderSort::RefundDueDate => $order->refundDueAt ?? throw new \LogicException('A pending refund has a due date.'),
            BackofficeOrderSort::TotalDescending, BackofficeOrderSort::TotalAscending => $order->totalGross,
            BackofficeOrderSort::PlacedDescending, BackofficeOrderSort::PlacedAscending => $order->placedAt,
        };

        return new self($sort, $value, $order->orderId);
    }

    /**
     * The position after an order in the sort by placement, newest first, which the history of a customer shares with
     * the list of the panel.
     */
    public static function placedAfter(\DateTimeImmutable $placedAt, string $orderId): self
    {
        return new self(BackofficeOrderSort::PlacedDescending, $placedAt, $orderId);
    }

    /**
     * @throws InvalidBackofficeOrderCursor also for a cursor of another sort
     */
    public static function decode(string $text, BackofficeOrderSort $sort): self
    {
        if ('' === $text || \strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidBackofficeOrderCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidBackofficeOrderCursor();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidBackofficeOrderCursor();
        }
        if (!\is_array($data) || 3 !== \count($data) || !isset($data['s'], $data['v'], $data['i']) || !\is_string($data['s']) || !\is_string($data['i'])) {
            throw new InvalidBackofficeOrderCursor();
        }
        if ($sort->value !== $data['s']) {
            throw new InvalidBackofficeOrderCursor();
        }
        if (1 !== preg_match(self::ID_PATTERN, $data['i'])) {
            throw new InvalidBackofficeOrderCursor();
        }

        return new self($sort, $sort->byTotal() ? self::decodeTotal($data['v']) : self::decodeMoment($data['v']), $data['i']);
    }

    public function encode(): string
    {
        $data = [
            's' => $this->sort->value,
            'v' => $this->value instanceof \DateTimeImmutable ? $this->value->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT) : $this->value,
            'i' => $this->id,
        ];

        return rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidBackofficeOrderCursor
     */
    private static function decodeTotal(mixed $value): int
    {
        // An amount of grosze is a whole, non-negative number of a JSON number (a text or a fraction is no amount).
        if (!\is_int($value) || $value < 0) {
            throw new InvalidBackofficeOrderCursor();
        }

        return $value;
    }

    /**
     * @throws InvalidBackofficeOrderCursor
     */
    private static function decodeMoment(mixed $value): \DateTimeImmutable
    {
        // Only a moment written in the exact format of encode() is a cursor: "2026-02-31" would be moved to March.
        // The shape is checked first: createFromFormat() throws a ValueError on a NUL byte.
        if (!\is_string($value) || 1 !== preg_match(self::TIME_PATTERN, $value)) {
            throw new InvalidBackofficeOrderCursor();
        }
        $at = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $value);
        if (false === $at || $at->format(self::TIME_FORMAT) !== $value) {
            throw new InvalidBackofficeOrderCursor();
        }
        // PostgreSQL has no year 0 (the year before 1 is 1 BC), so the query would fail with 22008.
        if ((int) $at->format('Y') < 1) {
            throw new InvalidBackofficeOrderCursor();
        }

        return $at;
    }
}
