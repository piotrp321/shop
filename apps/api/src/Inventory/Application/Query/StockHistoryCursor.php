<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * The position after a history entry in the order (occurred_at descending, id descending): the keyset of the stock
 * history. Handed out as opaque text, base64url without padding of a JSON object `{"t": time, "i": id}`, so the
 * order can change without changing the API.
 */
final readonly class StockHistoryCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    /** UTC with microseconds, so a cursor keeps the whole precision of the stored time. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** encode() writes UTC only; any other offset is not a cursor (and PostgreSQL rejects offsets beyond +-15:59). */
    private const string TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/D';

    private function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $id,
    ) {
    }

    public static function after(StockHistoryItem $item): self
    {
        return new self($item->occurredAt, $item->id);
    }

    /**
     * @throws InvalidStockHistoryCursor
     */
    public static function decode(string $text): self
    {
        if (\strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidStockHistoryCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidStockHistoryCursor();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidStockHistoryCursor();
        }
        if (!\is_array($data) || 2 !== \count($data) || !isset($data['t'], $data['i']) || !\is_string($data['t']) || !\is_string($data['i'])) {
            throw new InvalidStockHistoryCursor();
        }

        // Only a time written in the exact format of encode() is a cursor: "2026-02-31" would be moved to March.
        // The shape is checked first: createFromFormat() throws a ValueError on a NUL byte.
        if (1 !== preg_match(self::TIME_PATTERN, $data['t'])) {
            throw new InvalidStockHistoryCursor();
        }
        $occurredAt = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $data['t']);
        if (false === $occurredAt || $occurredAt->format(self::TIME_FORMAT) !== $data['t']) {
            throw new InvalidStockHistoryCursor();
        }
        // PostgreSQL has no year 0 (the year before 1 is 1 BC), so the query would fail with 22008.
        if ((int) $occurredAt->format('Y') < 1) {
            throw new InvalidStockHistoryCursor();
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $data['i'])) {
            throw new InvalidStockHistoryCursor();
        }

        return new self($occurredAt, $data['i']);
    }

    public function encode(): string
    {
        $time = $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);

        return rtrim(strtr(base64_encode(json_encode(['t' => $time, 'i' => $this->id], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
