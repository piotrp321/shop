<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Demo;

use App\Pricing\Application\Port\PricingReset;
use Doctrine\DBAL\Connection;

final readonly class DbalPricingReset implements PricingReset
{
    public function __construct(private Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE pricing_price_history, pricing_product_price');
    }
}
