<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * Whether each of up to MAX_ORDERS orders can still be paid, for payment:close-abandoned
 * (docs/features/async-reliability/spec.md, W6). The answer holds nothing of an order but that and its status for
 * Payment, so no access is checked.
 */
final readonly class GetOrderPayability
{
    public const int MAX_ORDERS = 100;

    /**
     * @param list<string> $orderIds
     */
    public function __construct(public array $orderIds)
    {
    }
}
