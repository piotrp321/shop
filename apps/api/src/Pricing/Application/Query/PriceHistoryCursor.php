<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The position after a history entry in the order (changed_at descending, id descending): the keyset of the price
 * history. Handed out as opaque text, base64url without padding of a JSON object `{"t": time, "i": id}`, so the
 * order can change without changing the API.
 */
final readonly class PriceHistoryCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    /** UTC with microseconds, so a cursor keeps the whole precision of the stored time. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    private function __construct(
        public \DateTimeImmutable $changedAt,
        public string $id,
    ) {
    }

    public static function after(PriceHistoryItem $item): self
    {
        return new self($item->changedAt, $item->id);
    }

    /**
     * @throws InvalidPriceHistoryCursor
     */
    public static function decode(string $text): self
    {
        if (\strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidPriceHistoryCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidPriceHistoryCursor();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPriceHistoryCursor();
        }
        if (!\is_array($data) || 2 !== \count($data) || !isset($data['t'], $data['i']) || !\is_string($data['t']) || !\is_string($data['i'])) {
            throw new InvalidPriceHistoryCursor();
        }

        // Only a time written in the exact format of encode() is a cursor: "2026-02-31" would be moved to March.
        $changedAt = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $data['t']);
        if (false === $changedAt || $changedAt->format(self::TIME_FORMAT) !== $data['t']) {
            throw new InvalidPriceHistoryCursor();
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $data['i'])) {
            throw new InvalidPriceHistoryCursor();
        }

        return new self($changedAt, $data['i']);
    }

    public function encode(): string
    {
        $time = $this->changedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);

        return rtrim(strtr(base64_encode(json_encode(['t' => $time, 'i' => $this->id], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
