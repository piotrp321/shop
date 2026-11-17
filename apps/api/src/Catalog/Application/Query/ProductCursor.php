<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Product\ProductId;

/**
 * The position after a product in a sort of the product list: (name, id) or (updated_at, id), in the direction of the
 * sort. Handed out as opaque text, base64url without padding of a JSON object `{"s": sort key, "v": name or moment of
 * the last change, "i": id}`, so the order can change without changing the API. A cursor is of the sort it was issued
 * for: with another one it is no cursor (the position would not mean the same).
 */
final readonly class ProductCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    private const int MAX_NAME_LENGTH = 200;
    /** UTC with microseconds, so a cursor keeps the whole precision of the stored moment. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';
    /** encode() writes UTC only; any other offset is not a cursor (and PostgreSQL rejects offsets beyond +-15:59). */
    private const string TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/D';

    private function __construct(
        public ProductSort $sort,
        public string|\DateTimeImmutable $value,
        public string $id,
    ) {
    }

    public static function after(BackofficeProduct $product, ProductSort $sort): self
    {
        return new self($sort, $sort->byUpdatedAt() ? $product->updatedAt : $product->name, $product->id);
    }

    /**
     * @throws InvalidProductCursor also for a cursor of another sort
     */
    public static function decode(string $text, ProductSort $sort): self
    {
        if (\strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidProductCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidProductCursor();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidProductCursor();
        }
        if (!\is_array($data) || 3 !== \count($data) || !isset($data['s'], $data['v'], $data['i']) || !\is_string($data['s']) || !\is_string($data['v']) || !\is_string($data['i'])) {
            throw new InvalidProductCursor();
        }
        if ($sort->value !== $data['s']) {
            throw new InvalidProductCursor();
        }

        try {
            $id = ProductId::fromString($data['i'])->toString();
        } catch (\InvalidArgumentException) {
            throw new InvalidProductCursor();
        }

        return new self($sort, $sort->byUpdatedAt() ? self::decodeMoment($data['v']) : self::decodeName($data['v']), $id);
    }

    public function encode(): string
    {
        $value = $this->value instanceof \DateTimeImmutable ? $this->value->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT) : $this->value;

        return rtrim(strtr(base64_encode(json_encode(['s' => $this->sort->value, 'v' => $value, 'i' => $this->id], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidProductCursor
     */
    private static function decodeName(string $name): string
    {
        // A name the database cannot store (NUL) or that no product can have (empty, over 200 characters) is no cursor.
        if ('' === $name || mb_strlen($name) > self::MAX_NAME_LENGTH || str_contains($name, "\0")) {
            throw new InvalidProductCursor();
        }

        return $name;
    }

    /**
     * @throws InvalidProductCursor
     */
    private static function decodeMoment(string $moment): \DateTimeImmutable
    {
        // Only a moment written in the exact format of encode() is a cursor: "2026-02-31" would be moved to March.
        // The shape is checked first: createFromFormat() throws a ValueError on a NUL byte.
        if (1 !== preg_match(self::TIME_PATTERN, $moment)) {
            throw new InvalidProductCursor();
        }
        $at = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $moment);
        // PostgreSQL has no year 0 (the year before 1 is 1 BC), so the query would fail with 22008.
        if (false === $at || $at->format(self::TIME_FORMAT) !== $moment || (int) $at->format('Y') < 1) {
            throw new InvalidProductCursor();
        }

        return $at;
    }
}
