<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Application\Port\SearchIndexMaintenance;

/**
 * The end of a rebuild, the same for the synchronous rebuild and for the finalization of the asynchronous one: the
 * switch of the alias to the new index, the deletion of the previous index once the alias left it, and the deletion of
 * a new index the alias never pointed to. A deletion that fails is a warning: the index stays as an orphan.
 */
final readonly class SearchIndexSwitch
{
    public function __construct(private SearchIndexMaintenance $maintenance)
    {
    }

    /**
     * Switches the alias from $previous to $new; deletes $new when the alias stayed on $previous and $previous when it
     * moved. An unknown outcome deletes nothing.
     */
    public function activate(string $new, string $previous, int $documents): RebuildReport
    {
        return match ($this->maintenance->activate($new, $previous)) {
            ActivationResult::Activated => $this->deletePrevious(RebuildReport::activated($previous, $new, $documents)),
            ActivationResult::NotActivated => $this->discard($new, RebuildReport::failed(
                \sprintf('The alias was not switched; it still points to "%s".', $previous),
                $previous,
                $new,
            )),
            ActivationResult::Unknown => RebuildReport::unknown($previous, $new),
        };
    }

    /**
     * Deletes the new index (with its rebuild alias); the alias never points to it, which deleteIndex checks again.
     */
    public function discard(string $new, RebuildReport $report): RebuildReport
    {
        try {
            $this->maintenance->deleteIndex($new);
        } catch (SearchUnavailable|SearchIndexError $e) {
            return $report->withWarning(\sprintf('The new index "%s" could not be deleted: %s', $new, $e->getMessage()));
        }

        return $report;
    }

    public function deletePrevious(RebuildReport $report): RebuildReport
    {
        \assert(null !== $report->previousIndex);
        try {
            $this->maintenance->deleteIndex($report->previousIndex);
        } catch (SearchUnavailable|SearchIndexError $e) {
            return $report->withWarning(\sprintf(
                'The previous index "%s" could not be deleted and stays as an orphan: %s',
                $report->previousIndex,
                $e->getMessage(),
            ));
        }

        return $report;
    }
}
