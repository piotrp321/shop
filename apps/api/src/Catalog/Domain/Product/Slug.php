<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

final readonly class Slug
{
    private const int MAX_LENGTH = 200;
    private const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

    public function __construct(private string $value)
    {
        if (\strlen($value) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('Slug is invalid.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
