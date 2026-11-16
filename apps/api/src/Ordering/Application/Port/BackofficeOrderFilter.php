<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Domain\OrderStatus;

/**
 * What the order list of the panel is limited to; every condition given applies, and the order of the list. The number
 * and the email are exact (the email regardless of letter case); the fragment is searched, regardless of letter case,
 * in the number, the email and the name together. The customer limits the list to the orders of one account of the
 * store.
 */
final readonly class BackofficeOrderFilter
{
    /** The order of the list: the due date of the refunds for the pending ones, else the given one (newest first by default). */
    public BackofficeOrderSort $sort;

    /**
     * @param bool                     $pendingRefunds  only the orders with a refund to do, sorted by its due date
     * @param string|null              $number          `R-` and digits, as stored
     * @param string|null              $email           as typed, compared in lower case
     * @param BackofficeOrderSort|null $sort            null for newest first, or for the due date with the pending refunds
     * @param string|null              $fragment        3 to 100 characters, as typed
     * @param \DateTimeImmutable|null  $placedFrom      the first instant of the orders placed, inclusive
     * @param \DateTimeImmutable|null  $placedBefore    the instant the orders must be placed before, exclusive
     * @param \DateTimeImmutable|null  $refundDueBefore the instant the refunds must be due before, exclusive: only the pending
     *                                                  refunds past their due date at that moment (the overdue ones)
     * @param string|null              $customerId      only the orders of this customer, newest first, with the status only
     *
     * @throws \LogicException a sort other than the due date with the pending refunds, or the due date without them; the
     *                         sort by the amount, or the pending refunds, with a search by fragment or a range of days; a due
     *                         moment without the pending refunds; a customer with anything but the status and the
     *                         sort newest first
     */
    public function __construct(
        public ?OrderStatus $status,
        public bool $pendingRefunds,
        public ?string $number,
        public ?string $email,
        ?BackofficeOrderSort $sort = null,
        public ?string $fragment = null,
        public ?\DateTimeImmutable $placedFrom = null,
        public ?\DateTimeImmutable $placedBefore = null,
        public ?\DateTimeImmutable $refundDueBefore = null,
        public ?string $customerId = null,
    ) {
        if ($pendingRefunds ? (null !== $sort && BackofficeOrderSort::RefundDueDate !== $sort) : BackofficeOrderSort::RefundDueDate === $sort) {
            throw new \LogicException('The due date is the sort of the pending refunds, and only of them.');
        }
        if (null !== $refundDueBefore && !$pendingRefunds) {
            throw new \LogicException('Only the pending refunds can be overdue.');
        }
        $sorted = $pendingRefunds ? BackofficeOrderSort::RefundDueDate : ($sort ?? BackofficeOrderSort::PlacedDescending);
        if ((null !== $placedFrom || null !== $placedBefore) && ($pendingRefunds || $sorted->byTotal())) {
            throw new \LogicException('No index serves a range of days with the pending refunds or with the sort by the amount.');
        }
        if (null !== $fragment && $sorted->byTotal()) {
            throw new \LogicException('No index serves a search by fragment with the sort by the amount.');
        }
        if (null !== $customerId && (BackofficeOrderSort::PlacedDescending !== $sorted || null !== $number || null !== $email || null !== $fragment || null !== $placedFrom || null !== $placedBefore)) {
            throw new \LogicException('The history of a customer is read newest first with the status filter only.');
        }
        $this->sort = $sorted;
    }
}
