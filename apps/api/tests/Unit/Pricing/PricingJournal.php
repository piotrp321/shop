<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

/**
 * What the doubles of a handler test did, in order, e.g. that the lock came before the clock.
 */
final class PricingJournal
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
