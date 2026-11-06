<?php

declare(strict_types=1);

namespace App\Shared\UI\Console;

use App\Shared\Infrastructure\Http\Cache\StorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Invalidates the whole catalog in both caches of the store: the `catalog` tag purged in Varnish, then revalidated in
 * the store. `make seed` runs it last, after the seeds (whose events invalidate nothing). Each layer gets up to
 * ATTEMPTS tries, $retryDelay seconds apart, for a store that is still starting or compiling the route (`next dev`).
 *
 * Exit codes: 0 both layers were invalidated, 2 a layer still failed after its last attempt (e.g. Varnish or the
 * store is down); after a failed purge the store is not revalidated.
 */
#[AsCommand(name: 'storefront:cache:purge-all', description: 'Purges the catalog in Varnish and revalidates every page of the store.')]
final readonly class PurgeAllStorefrontCacheCommand
{
    public const int ATTEMPTS = 5;
    public const int ERROR = 2;

    public function __construct(
        private StorefrontCache $cache,
        #[Autowire(param: 'app.storefront_cache.retry_delay')] private float $retryDelay,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $this->attempt($io, 'Varnish purge', $this->cache->purgeAll(...));
            $this->attempt($io, 'store revalidation', $this->cache->revalidateAll(...));
        } catch (StorefrontCacheFailure $failure) {
            $io->error('The storefront caches were not invalidated: '.$failure->getMessage());

            return self::ERROR;
        }

        $io->success('Varnish and the store pages were invalidated.');

        return Command::SUCCESS;
    }

    /**
     * @param \Closure(): void $layer
     *
     * @throws StorefrontCacheFailure the failure of the last attempt
     */
    private function attempt(SymfonyStyle $io, string $name, \Closure $layer): void
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $layer();

                return;
            } catch (StorefrontCacheFailure $failure) {
                if (self::ATTEMPTS === $attempt) {
                    throw $failure;
                }
                $io->warning(\sprintf('Attempt %d of %d of the %s failed (%s); retrying in %s s.', $attempt, self::ATTEMPTS, $name, $failure->getMessage(), $this->retryDelay));
                usleep((int) ($this->retryDelay * 1_000_000));
            }
        }
    }
}
