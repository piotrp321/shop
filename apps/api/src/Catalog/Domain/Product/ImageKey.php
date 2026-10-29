<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class ImageKey
{
    private const string KEY_PREFIX = 'originals/';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::pattern(), $value)) {
            throw new \InvalidArgumentException('ImageKey is invalid.');
        }

        return new self($value);
    }

    private static function pattern(): string
    {
        $extensions = implode('|', array_map(static fn (OriginalImageType $type): string => $type->value, OriginalImageType::cases()));

        return '#^'.self::KEY_PREFIX.'[a-f0-9]{64}\.('.$extensions.')$#D';
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
