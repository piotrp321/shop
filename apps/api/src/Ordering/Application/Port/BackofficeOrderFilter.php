<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderStatus;

/**
 * What the order list of the panel is limited to; every condition given applies. The number and the email are exact
 * (the email regardless of letter case).
 */
final readonly class BackofficeOrderFilter
{
    /**
     * @param bool        $pendingRefunds only the orders with a refund to do, sorted by its due date
     * @param string|null $number         `R-` and digits, as stored
     * @param string|null $email          as typed, compared in lower case
     */
    public function __construct(
        public ?OrderStatus $status,
        public bool $pendingRefunds,
        public ?string $number,
        public ?string $email,
    ) {
    }
}
