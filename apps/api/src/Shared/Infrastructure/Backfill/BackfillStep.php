<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backfill;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A step of app:backfill: fills data that a migration added the schema for (migrations change the schema only), after
 * the rollout of the new version. The modules implement it in their Infrastructure layer; app:backfill runs the steps
 * in descending priority (`#[AsTaggedItem(priority: …)]` on the implementation) and skips those completed before.
 *
 * A step is idempotent, works in batches, each in its own transaction, and is correct while the shop takes traffic:
 * it may be stopped at any point and run again from the start (app:backfill --force runs it again on purpose).
 */
#[AutoconfigureTag(self::TAG)]
interface BackfillStep
{
    public const string TAG = 'app.backfill_step';

    /**
     * Recorded in app_backfill once the step completed; at most 200 characters, unique among the steps. A new name is
     * a new step, which the next deployment runs.
     */
    public function name(): string;

    /**
     * @param \Closure(string): void $report takes a line for the output (progress, a summary)
     *
     * @throws \Throwable when the step cannot complete; it is not recorded and the run stops
     */
    public function run(\Closure $report): void;
}
