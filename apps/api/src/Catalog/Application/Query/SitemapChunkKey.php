<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Product\ProductId;

/**
 * The range of product ids of one sitemap chunk: from `from` inclusive to `to` exclusive, either end open (null).
 * Handed out as opaque text, base64url without padding of a JSON object `{"f": id|null, "t": id|null}`, so the
 * way chunks are cut can change without changing the API.
 */
final readonly class SitemapChunkKey
{
    private const int MAX_TEXT_LENGTH = 200;

    private function __construct(
        public ?string $from,
        public ?string $to,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when an end is not a UUID or the start is not below the end
     */
    public static function between(?string $from, ?string $to): self
    {
        $from = null === $from ? null : ProductId::fromString($from)->toString();
        $to = null === $to ? null : ProductId::fromString($to)->toString();
        // Lower-case UUID text sorts like the uuid type, which orders the products.
        if (null !== $from && null !== $to && $from >= $to) {
            throw new \InvalidArgumentException('The start of a chunk must be below its end.');
        }

        return new self($from, $to);
    }

    /**
     * @throws InvalidSitemapChunk
     */
    public static function decode(string $text): self
    {
        if (\strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidSitemapChunk();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidSitemapChunk();
        }

        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidSitemapChunk();
        }
        if (!\is_array($data) || 2 !== \count($data) || !\array_key_exists('f', $data) || !\array_key_exists('t', $data)) {
            throw new InvalidSitemapChunk();
        }
        if ((null !== $data['f'] && !\is_string($data['f'])) || (null !== $data['t'] && !\is_string($data['t']))) {
            throw new InvalidSitemapChunk();
        }

        try {
            return self::between($data['f'], $data['t']);
        } catch (\InvalidArgumentException) {
            throw new InvalidSitemapChunk();
        }
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode(['f' => $this->from, 't' => $this->to], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
