<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

enum PriceChangeSource: string
{
    /** A staff member in the panel. */
    case Staff = 'staff';
    /** The demo data (`pricing:seed-demo`). */
    case Seed = 'seed';
    /** `pricing:reconcile-tax --apply` after a rate or class change. */
    case TaxReconciliation = 'tax_reconciliation';
}
