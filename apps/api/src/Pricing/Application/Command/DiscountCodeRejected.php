<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\DiscountCode\DiscountCodeRejection;

/**
 * The code gives no discount when its usage is recorded (RedeemDiscountCode): why, with the problem type and the
 * Polish detail of the rejection. Ordering's adapter turns it into its own 409.
 */
final class DiscountCodeRejected extends \DomainException
{
    public function __construct(public readonly DiscountCodeRejection $rejection)
    {
        parent::__construct($rejection->detail());
    }
}
