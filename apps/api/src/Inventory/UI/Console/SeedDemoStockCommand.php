<?php

declare(strict_types=1);

namespace App\Inventory\UI\Console;

use App\Inventory\Application\Demo\DemoStockSeeder;
use App\Inventory\Application\Port\DemoStockSource;
use App\Inventory\Application\Port\InventoryReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes all stocks, reservations and the stock history, then sets the demo stock of every product (run after
 * catalog:seed-demo). The file and the products are checked before anything is removed.
 * Exit codes: 0 every product has a stock, 1 the guard refused, the stock file is invalid or a product has no
 * matching pattern (nothing was changed).
 */
#[AsCommand(name: 'inventory:seed-demo', description: 'Removes all stocks and sets the demo stock of the catalog products.')]
final readonly class SeedDemoStockCommand
{
    private const int LISTED_SLUGS = 10;

    public function __construct(
        private DemoStockSource $source,
        private DemoStockSeeder $seeder,
        private InventoryReset $reset,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Allow the reset in prod, where it deletes all stocks, reservations and the stock history')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes all stocks, reservations and the stock history. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        try {
            $stock = $this->source->load();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().' Nothing was changed.');

            return Command::FAILURE;
        }

        $unmatched = $this->seeder->unmatchedSlugs($stock);
        if ([] !== $unmatched) {
            $more = \count($unmatched) - self::LISTED_SLUGS;
            $io->error(\sprintf(
                'No pattern in the demo stock (fixtures/stock.yaml) matches %d demo products: %s%s. Nothing was changed.',
                \count($unmatched),
                implode(', ', \array_slice($unmatched, 0, self::LISTED_SLUGS)),
                $more > 0 ? \sprintf(' and %d more', $more) : '',
            ));

            return Command::FAILURE;
        }

        $this->reset->reset();
        $count = $this->seeder->seed($stock);

        $io->success(\sprintf('stocks: %d', $count));

        return Command::SUCCESS;
    }
}
