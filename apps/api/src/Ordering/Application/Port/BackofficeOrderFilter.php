<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Domain\OrderStatus;

/**
 * What the order list of the panel is limited to; every condition given applies, and the order of the list. The number
 * and the email are exact (the email regardless of letter case).
 */
final readonly class BackofficeOrderFilter
{
    /** The order of the list: the due date of the refunds for the pending ones, else the given one (newest first by default). */
    public BackofficeOrderSort $sort;

    /**
     * @param bool                     $pendingRefunds only the orders with a refund to do, sorted by its due date
     * @param string|null              $number         `R-` and digits, as stored
     * @param string|null              $email          as typed, compared in lower case
     * @param BackofficeOrderSort|null $sort           null for newest first, or for the due date with the pending refunds
     *
     * @throws \LogicException a sort other than the due date with the pending refunds, or the due date without them
     */
    public function __construct(
        public ?OrderStatus $status,
        public bool $pendingRefunds,
        public ?string $number,
        public ?string $email,
        ?BackofficeOrderSort $sort = null,
    ) {
        if ($pendingRefunds ? (null !== $sort && BackofficeOrderSort::RefundDueDate !== $sort) : BackofficeOrderSort::RefundDueDate === $sort) {
            throw new \LogicException('The due date is the sort of the pending refunds, and only of them.');
        }
        $this->sort = $pendingRefunds ? BackofficeOrderSort::RefundDueDate : ($sort ?? BackofficeOrderSort::PlacedDescending);
    }
}
