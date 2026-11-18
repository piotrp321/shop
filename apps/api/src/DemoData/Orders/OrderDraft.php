<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * What an order draws from the stream of its part before the catalog is read: the owner, the lines (candidate
 * products), the code, the provider, the outcome and the delays of its cycle, the staff members and the ids. The draws
 * are made in a fixed order whatever the catalog holds, so the orders do not depend on how the catalog answers.
 */
final readonly class OrderDraft
{
    /**
     * @param list<LineDraft>                                                                 $lines
     * @param array{pay: int, cancel: int, ship: int, deliver: int, return: int, settle: int} $delays seconds
     * @param array{ship: int, deliver: int, cancel: int, return: int, settle: int}           $staff  staff numbers
     */
    public function __construct(
        public int $index,
        public \DateTimeImmutable $placedAt,
        public string $id,
        public ?int $customer,
        public array $lines,
        public float $codeDraw,
        public string $provider,
        public float $outcome,
        public float $returnDraw,
        public array $delays,
        public array $staff,
        public string $paymentId,
        public string $usageId,
    ) {
    }
}
