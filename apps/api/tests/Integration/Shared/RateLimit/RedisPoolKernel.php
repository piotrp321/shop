<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use App\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The `test` kernel (its small limits) with the state of the rate limiters in the Redis pool of production: `when@test`
 * keeps it in memory, this kernel takes the storage of the production configuration back (the pool cache.rate_limiter
 * of config/packages/rate_limiter.yaml, REDIS_HOST with the timeouts of the DSN). Its container is cached apart from
 * the `test` one. The storage of order_placement is an InterruptibleStorage, so a test can break Redis between the
 * lock of a key and its counter.
 */
final class RedisPoolKernel extends Kernel
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir().'_redis_pool';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['rate_limiter' => array_fill_keys(
                ['order_placement', 'payment_start', 'payment_webhook'],
                ['policy' => 'sliding_window', 'storage_service' => null],
            )]);
            $container->register('test.interruptible_storage', InterruptibleStorage::class)
                ->setDecoratedService('limiter.storage.order_placement')
                ->setArguments([new Reference('.inner')]);
        });
    }
}
