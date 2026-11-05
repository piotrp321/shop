<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

/**
 * The calls of the in-memory ports of Ordering in the order they were made, so a test can check the order of the
 * steps of a placement.
 */
final class Journal
{
    /** @var list<string> */
    public array $calls = [];

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }
}
