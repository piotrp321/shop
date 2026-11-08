<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

enum DiscountKind: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}
