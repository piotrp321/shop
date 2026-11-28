<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\RebuildReport;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchIndexSwitch;
use App\Catalog\Application\Search\SearchRebuildRun;
use App\Catalog\Application\Search\SearchRebuildStatus;
use App\Catalog\Application\Search\SearchUnavailable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The finalization of an asynchronous rebuild run (docs/features/async-indexing/spec.md, "Finalizacja i jej
 * wznawianie"), on indexing.bus: no transaction spans a request to the index. It resumes from the saved status and
 * saves every stage before the next one: finalizing (verify) → verified → activating (the switch of the alias) →
 * activated (the deletion of the previous index) → succeeded. Every stage is idempotent and every move is conditional on
 * the status read, so a second handler of the same run (a redelivery while the first still works, a manual retry) does
 * at most the same work again: it never switches the alias twice and never overwrites a final status. A move lost to
 * another handler only makes this one read the run again and go on from where it is.
 *
 * A transient failure of the index is thrown, so the transport retries the message; the status stays the stage.
 */
#[AsMessageHandler(bus: 'indexing.bus')]
final readonly class FinalizeSearchRebuildHandler
{
    private SearchIndexSwitch $switch;

    public function __construct(
        private SearchRebuildRuns $runs,
        private SearchIndexMaintenance $maintenance,
        private Clock $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->switch = new SearchIndexSwitch($maintenance);
    }

    /**
     * @throws SearchUnavailable the index could not be reached; retried from the saved stage
     */
    public function __invoke(FinalizeSearchRebuild $message): void
    {
        while (null !== ($run = $this->runs->get($message->runId)) && !$run->status->isFinal()) {
            if ($run->status->takesBatches()) {
                return;
            }
            match ($run->status) {
                SearchRebuildStatus::Finalizing => $this->verify($run),
                SearchRebuildStatus::Verified => $this->move($run, SearchRebuildStatus::Activating),
                SearchRebuildStatus::Activating => $this->activate($run),
                SearchRebuildStatus::Activated => $this->deletePrevious($run),
                default => null,
            };
        }
    }

    private function verify(SearchRebuildRun $run): void
    {
        try {
            $this->maintenance->verify($run->targetIndex, $run->documents);
        } catch (SearchIndexError $e) {
            // The alias never pointed to the new index: once this handler failed the run, the index goes.
            if ($this->move($run, SearchRebuildStatus::Failed, $e->getMessage())) {
                $this->warn($run, $this->switch->discard($run->targetIndex, RebuildReport::failed($e->getMessage())));
            }

            return;
        }
        $this->move($run, SearchRebuildStatus::Verified);
    }

    /**
     * The alias is read first: on the previous index it is switched (one atomic request that expects the previous
     * index), on the target it was switched already (by a request that reached Elasticsearch before a crash, or by
     * another handler). Anywhere else the run fails with both indexes kept.
     */
    private function activate(SearchRebuildRun $run): void
    {
        $active = $this->maintenance->activeIndex();
        if ($active === $run->previousIndex) {
            $this->maintenance->activate($run->targetIndex, $run->previousIndex);
            // Whatever the answer said, the alias as it is now decides (another handler may have switched it).
            $active = $this->maintenance->activeIndex();
            if ($active === $run->previousIndex) {
                if ($this->move($run, SearchRebuildStatus::Failed, \sprintf('The alias was not switched; it still points to "%s".', $run->previousIndex))) {
                    $this->warn($run, $this->switch->discard($run->targetIndex, RebuildReport::failed('not switched')));
                }

                return;
            }
        }
        if ($active === $run->targetIndex) {
            $this->move($run, SearchRebuildStatus::Activated);

            return;
        }
        $this->move($run, SearchRebuildStatus::Failed, \sprintf(
            'The alias points to "%s", neither the previous index "%s" nor the new one "%s"; both are kept.',
            $active,
            $run->previousIndex,
            $run->targetIndex,
        ));
    }

    private function deletePrevious(SearchRebuildRun $run): void
    {
        $this->warn($run, $this->switch->deletePrevious(RebuildReport::activated($run->previousIndex, $run->targetIndex, $run->documents)));
        if ($this->move($run, SearchRebuildStatus::Succeeded)) {
            $this->logger->info('Search index rebuild finished.', ['run' => $run->id, 'index' => $run->targetIndex, 'documents' => $run->documents]);
        }
    }

    private function move(SearchRebuildRun $run, SearchRebuildStatus $to, ?string $failure = null): bool
    {
        return $this->runs->move($run->id, [$run->status], $to, $this->clock->now(), $failure);
    }

    private function warn(SearchRebuildRun $run, RebuildReport $report): void
    {
        foreach ($report->warnings as $warning) {
            $this->logger->warning($warning, ['run' => $run->id]);
        }
    }
}
