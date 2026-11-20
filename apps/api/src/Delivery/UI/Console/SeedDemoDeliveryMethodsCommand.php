<?php

declare(strict_types=1);

namespace App\Delivery\UI\Console;

use App\Delivery\Application\Demo\DemoDeliveryMethodSeeder;
use App\Delivery\Application\Port\DeliveryReset;
use App\Delivery\Application\Port\DemoDeliveryMethodSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes every delivery method and creates the demo ones of fixtures/delivery-methods.yaml, all active.
 * Exit codes: 0 the methods are in place, 1 the guard refused or the file is invalid (nothing was changed); a database
 * failure ends the command with the exception.
 */
#[AsCommand(name: 'delivery:seed-demo', description: 'Removes all delivery methods and creates the demo ones.')]
final readonly class SeedDemoDeliveryMethodsCommand
{
    public function __construct(
        private DemoDeliveryMethodSource $source,
        private DemoDeliveryMethodSeeder $seeder,
        private DeliveryReset $reset,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Allow the reset in prod, where it deletes all delivery methods')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes all delivery methods. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        try {
            $methods = $this->source->load();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage().' Nothing was changed.');

            return Command::FAILURE;
        }

        $this->reset->reset();
        $created = $this->seeder->seed($methods);

        $io->success(\sprintf('delivery methods: %d', $created));

        return Command::SUCCESS;
    }
}
