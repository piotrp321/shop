<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

interface DiscountCodeUsageRepository
{
    /**
     * Writes a new usage. An order has at most one: a second usage of the same order is a logic error.
     */
    public function add(DiscountCodeUsage $usage): void;

    /**
     * The usages of the code that are not released, as stored now.
     */
    public function activeUsesOf(DiscountCodeId $codeId): int;
}
