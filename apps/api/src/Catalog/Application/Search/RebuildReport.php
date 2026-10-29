<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * What a rebuild did. $outcome says where the alias is: on the new index, still on the previous one, or unknown.
 */
final readonly class RebuildReport
{
    /**
     * @param list<string> $warnings problems that did not fail the rebuild (e.g. an index that could not be deleted)
     */
    private function __construct(
        public ActivationResult $outcome,
        public ?string $previousIndex,
        public ?string $newIndex,
        public int $documents,
        public ?string $failure,
        public array $warnings,
    ) {
    }

    public static function activated(string $previousIndex, string $newIndex, int $documents): self
    {
        return new self(ActivationResult::Activated, $previousIndex, $newIndex, $documents, null, []);
    }

    public static function failed(string $failure, ?string $previousIndex = null, ?string $newIndex = null): self
    {
        return new self(ActivationResult::NotActivated, $previousIndex, $newIndex, 0, $failure, []);
    }

    public static function unknown(string $previousIndex, string $newIndex): self
    {
        return new self(
            ActivationResult::Unknown,
            $previousIndex,
            $newIndex,
            0,
            \sprintf('The alias state is unknown: it may point to "%s" or "%s". Nothing was deleted.', $previousIndex, $newIndex),
            [],
        );
    }

    public function withWarning(string $warning): self
    {
        return new self($this->outcome, $this->previousIndex, $this->newIndex, $this->documents, $this->failure, [...$this->warnings, $warning]);
    }
}
