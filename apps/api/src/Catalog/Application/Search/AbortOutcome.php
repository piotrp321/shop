<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

final readonly class AbortOutcome
{
    /**
     * @param list<string> $warnings
     */
    private function __construct(public bool $aborted, public string $message, public array $warnings)
    {
    }

    /**
     * @param list<string> $warnings e.g. the index that could not be deleted and stays as an orphan
     */
    public static function aborted(string $runId, string $index, array $warnings): self
    {
        return new self(true, [] === $warnings
            ? \sprintf('The run %s was aborted and its index "%s" deleted.', $runId, $index)
            : \sprintf('The run %s was aborted; its index "%s" stays as an orphan.', $runId, $index), $warnings);
    }

    public static function refused(string $message): self
    {
        return new self(false, $message, []);
    }
}
