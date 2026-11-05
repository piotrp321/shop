<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Demo;

use App\Inventory\Application\Demo\DemoStockSeeder;
use App\Inventory\Infrastructure\Demo\YamlDemoStockSource;
use App\Inventory\UI\Console\SeedDemoStockCommand;
use App\Tests\Support\InMemory\RecordingMessageBus;
use App\Tests\Unit\Inventory\InMemoryVariantsForInventory;
use App\Tests\Unit\Inventory\SpyInventoryReset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDemoStockCommandTest extends TestCase
{
    private const array STOCKED = [
        '00000000-0000-4000-8000-000000000001' => 'namiot-turystyczny-norvane-biwak-2/zielony',
        '00000000-0000-4000-8000-000000000002' => 'mapa-turystyczna-tarvik-press-gorce-skladana/standard',
    ];

    private RecordingMessageBus $bus;
    private SpyInventoryReset $reset;
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testResetsAndSetsTheStockOfEveryVariant(): void
    {
        $tester = $this->tester(self::STOCKED);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertCount(2, $this->bus->dispatched);
        self::assertStringContainsString('stocks: 2', $tester->getDisplay());
    }

    public function testVariantWithoutPatternFailsBeforeAnyChange(): void
    {
        $tester = $this->tester([
            ...self::STOCKED,
            '00000000-0000-4000-8000-000000000003' => 'kajak-turystyczny-vilmar-rzeka-1/zielony',
        ]);

        $exitCode = $tester->execute([]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1/zielony', $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testManyVariantsWithoutPatternAreSummarised(): void
    {
        $variants = self::STOCKED;
        for ($i = 10; $i < 25; ++$i) {
            $variants[\sprintf('00000000-0000-4000-8000-%012d', $i)] = 'kajak-'.$i.'/standard';
        }
        $tester = $this->tester($variants);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('15 demo variants', $display);
        self::assertStringContainsString('kajak-19/standard', $display);
        self::assertStringNotContainsString('kajak-20/standard', $display);
    }

    public function testInvalidStockFileFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(self::STOCKED, "\"namiot-*\": -5\n");

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Nothing was changed', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testMissingStockFileFailsBeforeAnyChange(): void
    {
        $this->bus = new RecordingMessageBus();
        $this->reset = new SpyInventoryReset();
        $application = new Application();
        $application->addCommand(new SeedDemoStockCommand(
            new YamlDemoStockSource('/nonexistent/stock.yaml'),
            new DemoStockSeeder(new InMemoryVariantsForInventory(self::STOCKED), $this->bus),
            $this->reset,
            'test',
        ));
        $tester = new CommandTester($application->find('inventory:seed-demo'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('does not exist', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testProdWithoutForceResetFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(self::STOCKED, environment: 'prod');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testProdWithForceResetSeeds(): void
    {
        $tester = $this->tester(self::STOCKED, environment: 'prod');

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-reset' => true]), $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertCount(2, $this->bus->dispatched);
    }

    /**
     * @param array<string, string> $variants variant id => key
     */
    private function tester(array $variants, string $stock = "\"namiot-*\": 30\n\"mapa-*\": 0\n", string $environment = 'test'): CommandTester
    {
        $this->file = tempnam(sys_get_temp_dir(), 'stock');
        file_put_contents($this->file, $stock);
        $this->bus = new RecordingMessageBus();
        $this->reset = new SpyInventoryReset();
        $application = new Application();
        $application->addCommand(new SeedDemoStockCommand(
            new YamlDemoStockSource($this->file),
            new DemoStockSeeder(new InMemoryVariantsForInventory($variants), $this->bus),
            $this->reset,
            $environment,
        ));

        return new CommandTester($application->find('inventory:seed-demo'));
    }
}
