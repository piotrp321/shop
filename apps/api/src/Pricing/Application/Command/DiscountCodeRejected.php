<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\DiscountCode\DiscountCodeRejection;

/**
 * The code gives no discount when its usage is recorded (RedeemDiscountCode): why (`invalid`, `used_up`,
 * `below_minimum`), with the problem type, the Polish detail (the message) and the minimum of DiscountCodeRejection.
 * Ordering's adapter turns it into its own 409.
 */
final class DiscountCodeRejected extends \DomainException
{
    public readonly string $reason;
    public readonly string $type;
    /** The effective minimum in grosze, only for `below_minimum`. */
    public readonly ?int $minimumOrderGross;

    public function __construct(DiscountCodeRejection $rejection)
    {
        parent::__construct($rejection->detail());
        $this->reason = $rejection->reason->value;
        $this->type = $rejection->problemType();
        $this->minimumOrderGross = $rejection->minimumOrderGross;
    }
}
