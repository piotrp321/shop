<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Demo;

use App\Ordering\UI\Console\SeedDemoOrdersCommand;
use App\Tests\Unit\Ordering\SpyOrderingReset;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDemoOrdersCommandTest extends TestCase
{
    private SpyOrderingReset $reset;

    protected function setUp(): void
    {
        $this->reset = new SpyOrderingReset();
    }

    public function testRemovesAllOrders(): void
    {
        $tester = $this->tester('dev');

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertStringContainsString('orders removed', $tester->getDisplay());
    }

    public function testProdWithoutForceResetRefusesWithoutAnyChange(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame(0, $this->reset->resets);
    }

    public function testProdWithForceResetRemovesAllOrders(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-reset' => true]));
        self::assertSame(1, $this->reset->resets);
    }

    private function tester(string $environment): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SeedDemoOrdersCommand($this->reset, $environment));

        return new CommandTester($application->find('ordering:seed-demo'));
    }
}
