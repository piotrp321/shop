<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Product\ProductId;

/**
 * The position after a product in the order (name, id): the keyset of the product list. Handed out as opaque text,
 * base64url without padding of a JSON object `{"n": name, "i": id}`, so the order can change without changing the API.
 */
final readonly class ProductCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    private const int MAX_NAME_LENGTH = 200;

    private function __construct(
        public string $name,
        public string $id,
    ) {
    }

    public static function after(BackofficeProduct $product): self
    {
        return new self($product->name, $product->id);
    }

    /**
     * @throws InvalidProductCursor
     */
    public static function decode(string $text): self
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
        if (!\is_array($data) || 2 !== \count($data) || !isset($data['n'], $data['i']) || !\is_string($data['n']) || !\is_string($data['i'])) {
            throw new InvalidProductCursor();
        }
        // A name the database cannot store (NUL) or that no product can have (empty, over 200 characters) is no cursor.
        if ('' === $data['n'] || mb_strlen($data['n']) > self::MAX_NAME_LENGTH || str_contains($data['n'], "\0")) {
            throw new InvalidProductCursor();
        }

        try {
            $id = ProductId::fromString($data['i'])->toString();
        } catch (\InvalidArgumentException) {
            throw new InvalidProductCursor();
        }

        return new self($data['n'], $id);
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode(['n' => $this->name, 'i' => $this->id], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }
}
