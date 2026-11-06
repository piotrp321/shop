<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * An order as Payment sees it at the start of a payment and on the status page (from Ordering, without personal
 * data): its number, status, gross total in minor units, deadline, and whether it is payable now (new and before the
 * deadline, decided by Ordering).
 */
final readonly class OrderToPay
{
    public function __construct(
        public OrderId $orderId,
        public string $number,
        public OrderState $state,
        public int $totalGross,
        public Currency $currency,
        public \DateTimeImmutable $paymentDeadline,
        public bool $payable,
    ) {
    }

    /**
     * A new payment is possible only for a payable order.
     *
     * @throws OrderAlreadyPaid
     * @throws PaymentDeadlinePassed cancelled, or new past the deadline
     */
    public function ensurePaymentCanStart(): void
    {
        if (OrderState::Paid === $this->state) {
            throw new OrderAlreadyPaid();
        }
        if (OrderState::New !== $this->state || !$this->payable) {
            throw new PaymentDeadlinePassed();
        }
    }

    /**
     * @throws \InvalidArgumentException a total of zero, which no payment pays
     */
    public function amount(): Amount
    {
        return Amount::of($this->totalGross, $this->currency);
    }
}
