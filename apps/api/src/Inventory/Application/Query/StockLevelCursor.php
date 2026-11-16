<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;

/**
 * The position after a product in the stock list of a level: the products of a level come in ascending order of their
 * ids. Handed out as opaque text, base64url without padding of a JSON object `{"l": the level, "p": the product id}`;
 * a cursor of one level (or of the list by name) is no cursor of another.
 */
final readonly class StockLevelCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    /** The ids of products as stored: lower-case UUIDs. */
    private const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private function __construct(
        public AvailabilityLevel $level,
        public ProductId $productId,
    ) {
    }

    public static function after(AvailabilityLevel $level, ProductId $productId): self
    {
        return new self($level, $productId);
    }

    /**
     * @throws InvalidStockListCursor also for a cursor of another level
     */
    public static function decode(string $text, AvailabilityLevel $level): self
    {
        if ('' === $text || \strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidStockListCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidStockListCursor();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidStockListCursor();
        }
        if (!\is_array($data) || 2 !== \count($data) || !isset($data['l'], $data['p']) || !\is_string($data['l']) || !\is_string($data['p'])) {
            throw new InvalidStockListCursor();
        }
        if ($level->value !== $data['l'] || 1 !== preg_match(self::ID_PATTERN, $data['p'])) {
            throw new InvalidStockListCursor();
        }

        return new self($level, ProductId::fromString($data['p']));
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode(['l' => $this->level->value, 'p' => $this->productId->toString()], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
