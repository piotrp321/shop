<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

enum TransactionStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
