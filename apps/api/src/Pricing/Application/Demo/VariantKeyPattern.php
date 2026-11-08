<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

/**
 * A pattern of Catalog variant keys (`<product slug>/<label slug>`, `<product slug>/standard` for the default
 * variant): `*` matches any run of characters (also none, also `/`) and the pattern has to match the whole key. Shared
 * by the demo prices and the demo promotions.
 */
final readonly class VariantKeyPattern
{
    private string $regex;

    /**
     * @throws \InvalidArgumentException on a pattern without a letter or digit or with a character outside a-z, 0-9, "-", "/" and "*"
     */
    public function __construct(public string $pattern)
    {
        if (1 !== preg_match('/[a-z0-9]/', $pattern)) {
            throw new \InvalidArgumentException(\sprintf('The variant key pattern "%s" must contain a letter or digit.', $pattern));
        }
        if (1 !== preg_match('~^[a-z0-9*/-]+$~D', $pattern)) {
            throw new \InvalidArgumentException(\sprintf('The variant key pattern "%s" may only contain a-z, 0-9, "-", "/" and the wildcard "*".', $pattern));
        }

        $this->regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/D';
    }

    public function matches(string $key): bool
    {
        return 1 === preg_match($this->regex, $key);
    }
}
