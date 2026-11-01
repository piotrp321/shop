<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

enum TaxDiscrepancyOutcome: string
{
    /** Reported only (a run without --apply). */
    case Found = 'found';

    /** Repriced and saved. */
    case Fixed = 'fixed';

    /** Refused twice by a concurrent change of the price; left as it is. */
    case ModifiedConcurrently = 'modified_concurrently';

    /** The class the price should have has no VAT rate; left as it is. */
    case NoRate = 'no_rate';
}
