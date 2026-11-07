<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

final readonly class PaymentProviderView
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
    }
}
