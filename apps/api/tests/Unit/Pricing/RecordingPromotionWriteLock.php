<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\PromotionWriteLock;

final readonly class RecordingPromotionWriteLock implements PromotionWriteLock
{
    public function __construct(private PricingJournal $journal = new PricingJournal())
    {
    }

    public function exclusive(): void
    {
        $this->journal->add('lock exclusive');
    }

    public function shared(): void
    {
        $this->journal->add('lock shared');
    }
}
