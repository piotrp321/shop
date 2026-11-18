<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes the statistics of the tables the generator fills: the dashboard of the panel counts the products from the
 * statistics of catalog_product, and the planner needs them after a load of this size.
 * Exit codes: 0 done.
 */
#[AsCommand(name: 'demo-data:analyze', description: 'Runs ANALYZE on the tables filled by the generator.')]
final readonly class AnalyzeCommand
{
    /** The tables of the steps; a later step adds its tables here. */
    private const array TABLES = [
        'catalog_category', 'catalog_product', 'catalog_product_variant', 'catalog_product_category',
        'pricing_variant_price', 'pricing_price_history', 'pricing_promotion', 'pricing_promotion_variant', 'pricing_discount_code',
        'ordering_order', 'ordering_order_line', 'ordering_order_status_change', 'payment_payment', 'pricing_discount_code_usage',
        'inventory_stock_item', 'inventory_reservation', 'inventory_reservation_line', 'inventory_stock_movement',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('ANALYZE '.$table);
        }
        $io->success('analyzed '.implode(', ', self::TABLES));

        return Command::SUCCESS;
    }
}
