<?php

declare(strict_types=1);

namespace App\Pricing\UI\Console;

use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Application\Demo\DemoPromotionSeeder;
use App\Pricing\Application\Port\DemoPriceSource;
use App\Pricing\Application\Port\DemoPromotionSource;
use App\Pricing\Application\Port\PricingReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes all prices, their history and all promotions, then sets the demo price of every variant, matched by its key
 * (run after catalog:seed-demo), and plans the demo promotions on the variants their patterns match. Exit codes: 0
 * every variant has a price and the promotions are planned, 1 the guard refused, a file is invalid, a variant has no
 * matching price pattern or a promotion has no variant (nothing was changed).
 */
#[AsCommand(name: 'pricing:seed-demo', description: 'Removes all prices and promotions, sets the demo prices of the catalog variants and plans the demo promotions.')]
final readonly class SeedDemoPricesCommand
{
    private const int LISTED_KEYS = 10;

    public function __construct(
        private DemoPriceSource $source,
        private DemoPriceSeeder $seeder,
        private DemoPromotionSource $promotionSource,
        private DemoPromotionSeeder $promotionSeeder,
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

        $unpriced = $this->seeder->unpricedKeys($prices);
        if ([] !== $unpriced) {
            $more = \count($unpriced) - self::LISTED_KEYS;
            $io->error(\sprintf(
                'No pattern in the demo prices (fixtures/prices.yaml) matches %d demo variants: %s%s. Nothing was changed.',
                \count($unpriced),
                implode(', ', \array_slice($unpriced, 0, self::LISTED_KEYS)),
                $more > 0 ? \sprintf(' and %d more', $more) : '',
            ));

            return Command::FAILURE;
        }

        try {
            $promotions = $this->promotionSource->load();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().' Nothing was changed.');

            return Command::FAILURE;
        }

        $problems = $this->promotionSeeder->problems($promotions);
        if ([] !== $problems) {
            $io->error(\sprintf('The demo promotions (fixtures/promotions.yaml) cannot be planned: %s. Nothing was changed.', implode('; ', $problems)));

            return Command::FAILURE;
        }

        $this->reset->reset();
        $count = $this->seeder->seed($prices);
        $planned = $this->promotionSeeder->seed($promotions);

        $io->success(\sprintf('prices: %d, promotions: %d', $count, $planned));

        return Command::SUCCESS;
    }
}
