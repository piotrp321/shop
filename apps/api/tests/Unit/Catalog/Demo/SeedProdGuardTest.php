<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Tests\Support\InMemory\FixedCountProductIndexer;
use Symfony\Component\Console\Command\Command;

final class SeedProdGuardTest extends SeedCommandTestCase
{
    public function testProdWithoutForceResetFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]), 'prod');

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testProdWithForceResetResetsAndSeeds(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]), 'prod');

        $exitCode = $tester->execute(['--force-reset' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
        self::assertNotSame([], $this->bus->dispatched);
    }

    public function testNonProdResetsWithoutTheFlag(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([10]), 'dev');

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(1, $this->reset->resets);
    }

    public function testInvalidDefinitionFailsBeforeAnyChange(): void
    {
        $tester = $this->tester(
            new FixedCountProductIndexer([10]),
            definition: __DIR__.'/../../../Fixtures/demo/invalid-tax-category.yaml',
        );

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('reduced', $tester->getDisplay());
        self::assertStringContainsString('Nothing was changed', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, $this->reset->resets);
        self::assertSame([], $this->bus->dispatched);
    }
}
