<?php

declare(strict_types=1);

namespace App\DemoData\Profile;

/**
 * What every step of one run shares: the profile with the counts, the namespace of the seed, the size of a part and
 * the reference moment.
 */
final readonly class RunContext
{
    public function __construct(
        public DemoProfile $profile,
        public string $namespace,
        public int $partSize,
        public \DateTimeImmutable $anchor,
    ) {
    }

    public function productParts(): int
    {
        return intdiv($this->profile->products + $this->partSize - 1, $this->partSize);
    }
}
