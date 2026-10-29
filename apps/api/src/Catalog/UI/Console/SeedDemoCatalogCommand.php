<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Demo\DemoCatalogLoader;
use App\Catalog\Application\Port\CatalogReset;
use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resets the catalog and loads the deterministic demo data. Exit codes: 0 the index holds every product,
 * 1 the guard refused, the definition is invalid or the index did not catch up in time.
 */
#[AsCommand(name: 'catalog:seed-demo', description: 'Resets the catalog and loads the demo categories and products.')]
final readonly class SeedDemoCatalogCommand
{
    public function __construct(
        private DemoCatalogLoader $loader,
        private CatalogReset $reset,
        private ProductIndexer $indexer,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        private float $pollIntervalSeconds = 0.5,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Seconds to wait for the search index to catch up with the database')]
        float $timeout = 60.0,
        #[Option(description: 'Allow the reset in prod, where it deletes the catalog, its images and the search index')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes the whole catalog, its images and the search index. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        try {
            $catalog = $this->loader->plan();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().' Nothing was changed.');

            return Command::FAILURE;
        }

        $this->reset->reset();
        $this->loader->load($catalog);

        $categories = \count($catalog->categories);
        $products = \count($catalog->products);
        $indexed = $this->waitForIndex($products, $timeout, $failure);
        if ($indexed !== $products) {
            $io->error(\sprintf(
                'The search index did not catch up within %s s: %d products in the database, %s documents in the index.%s Is the worker running?',
                rtrim(rtrim(number_format($timeout, 1, '.', ''), '0'), '.'),
                $products,
                null === $indexed ? 'unknown' : (string) $indexed,
                null === $failure ? '' : ' Last error: '.$failure,
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf('categories: %d, products: %d, indexed: %d', $categories, $products, $indexed));

        return Command::SUCCESS;
    }

    /**
     * @param-out string|null $failure the last search error, when the count could not be read at the end
     *
     * @return int|null the last count read from the index, null when it could not be read at all
     */
    private function waitForIndex(int $expected, float $timeout, ?string &$failure): ?int
    {
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
        $count = null;
        $failure = null;

        while (true) {
            try {
                $count = $this->indexer->countDocuments();
                $failure = null;
                if ($count === $expected) {
                    return $count;
                }
            } catch (SearchUnavailable|SearchIndexError $e) {
                $failure = $e->getMessage();
            }

            $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
            if ($remaining <= 0) {
                return $count;
            }
            usleep((int) (min($this->pollIntervalSeconds, $remaining) * 1_000_000));
        }
    }
}
