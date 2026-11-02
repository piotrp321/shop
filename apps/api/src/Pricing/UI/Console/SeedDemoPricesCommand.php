<?php

declare(strict_types=1);

namespace App\Pricing\UI\Console;

use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Application\Port\DemoPriceSource;
use App\Pricing\Application\Port\PricingReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes all prices and their history, then sets the demo price of every product (run after catalog:seed-demo).
 * Exit codes: 0 every product has a price, 1 the guard refused, the price file is invalid or a product has no
 * matching pattern (nothing was changed).
 */
#[AsCommand(name: 'pricing:seed-demo', description: 'Removes all prices and sets the demo prices of the catalog products.')]
final readonly class SeedDemoPricesCommand
{
    private const int LISTED_SLUGS = 10;

    public function __construct(
        private DemoPriceSource $source,
        private DemoPriceSeeder $seeder,
        private PricingReset $reset,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Allow the reset in prod, where it deletes all prices and their history')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes all prices and their history. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        try {
            $prices = $this->source->load();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().' Nothing was changed.');

            return Command::FAILURE;
        }

        $unpriced = $this->seeder->unpricedSlugs($prices);
        if ([] !== $unpriced) {
            $more = \count($unpriced) - self::LISTED_SLUGS;
            $io->error(\sprintf(
                'No pattern in the demo prices (fixtures/prices.yaml) matches %d demo products: %s%s. Nothing was changed.',
                \count($unpriced),
                implode(', ', \array_slice($unpriced, 0, self::LISTED_SLUGS)),
                $more > 0 ? \sprintf(' and %d more', $more) : '',
            ));

            return Command::FAILURE;
        }

        $this->reset->reset();
        $count = $this->seeder->seed($prices);

        $io->success(\sprintf('prices: %d', $count));

        return Command::SUCCESS;
    }
}
