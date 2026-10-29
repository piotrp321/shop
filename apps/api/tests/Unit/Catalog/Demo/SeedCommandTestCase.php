<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Catalog\Application\Demo\DemoCatalogLoader;
use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Infrastructure\Demo\YamlDemoCatalogSource;
use App\Catalog\UI\Console\SeedDemoCatalogCommand;
use App\Tests\Support\InMemory\InMemoryProductImageStorage;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Support\InMemory\SpyCatalogReset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

abstract class SeedCommandTestCase extends TestCase
{
    protected RecordingMessageBus $bus;
    protected SpyCatalogReset $reset;

    protected function tester(ProductIndexer $indexer, string $environment = 'test'): CommandTester
    {
        $this->bus = new RecordingMessageBus();
        $this->reset = new SpyCatalogReset();
        $source = new YamlDemoCatalogSource(
            __DIR__.'/../../../Fixtures/demo/catalog.yaml',
            __DIR__.'/../../../Fixtures/images',
        );
        $loader = new DemoCatalogLoader($this->bus, new InMemoryProductImageStorage());
        $application = new Application();
        $application->addCommand(new SeedDemoCatalogCommand($source, $loader, $this->reset, $indexer, $environment, 0.01));

        return new CommandTester($application->find('catalog:seed-demo'));
    }
}
