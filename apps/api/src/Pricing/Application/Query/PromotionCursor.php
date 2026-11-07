<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\ListedPromotion;

/**
 * The position after a promotion in the order of the list (starts_at descending, then id). Handed out as opaque text,
 * base64url without padding of a JSON object `{"t": start, "i": id}`, so the order can change without changing the
 * API. The start of a promotion is a whole second.
 */
final readonly class PromotionCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    /** UTC, whole seconds: the moments of a promotion. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:sP';
    /** encode() writes UTC only; any other offset is not a cursor. */
    private const string TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D';
    /** The ids of promotions as stored: lower-case UUIDs. */
    private const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private function __construct(
        public \DateTimeImmutable $startsAt,
        public string $id,
    ) {
    }

    public static function after(ListedPromotion $promotion): self
    {
        return new self($promotion->startsAt, $promotion->id);
    }

    /**
     * @throws InvalidPromotionCursor
     */
    public static function decode(string $text): self
    {
        if ('' === $text || \strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidPromotionCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidPromotionCursor();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPromotionCursor();
        }
        if (!\is_array($data) || 2 !== \count($data) || !isset($data['t'], $data['i']) || !\is_string($data['t']) || !\is_string($data['i'])) {
            throw new InvalidPromotionCursor();
        }

        // Only a moment written in the exact format of encode() is a cursor: "2026-02-31" would be moved to March.
        // The shape is checked first: createFromFormat() throws a ValueError on a NUL byte.
        if (1 !== preg_match(self::TIME_PATTERN, $data['t'])) {
            throw new InvalidPromotionCursor();
        }
        $startsAt = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $data['t']);
        if (false === $startsAt || $startsAt->format(self::TIME_FORMAT) !== $data['t'] || (int) $startsAt->format('Y') < 1) {
            throw new InvalidPromotionCursor();
        }
        if (1 !== preg_match(self::ID_PATTERN, $data['i'])) {
            throw new InvalidPromotionCursor();
        }

        return new self($startsAt, $data['i']);
    }

    public function encode(): string
    {
        $data = ['t' => $this->startsAt->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT), 'i' => $this->id];

        return rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
