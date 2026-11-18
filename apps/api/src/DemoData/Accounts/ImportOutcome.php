<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * The outcome of the import of one batch: written (the number of added accounts and the skipped ones, each with the id
 * of the existing account with its username, null when the directory gave none) or rejected as a whole with an HTTP
 * status (nothing of the batch is written).
 */
final readonly class ImportOutcome
{
    /**
     * @param array<string, string|null> $skipped
     */
    private function __construct(
        public ?int $rejectedWith,
        public int $added,
        public array $skipped,
    ) {
    }

    /**
     * @param array<string, string|null> $skipped the id of the existing account by the username of the skipped one
     */
    public static function imported(int $added, array $skipped): self
    {
        return new self(null, $added, $skipped);
    }

    public static function rejected(int $status): self
    {
        return new self($status, 0, []);
    }
}
