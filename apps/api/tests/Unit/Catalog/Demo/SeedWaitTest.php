<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Demo;

use App\Tests\Support\InMemory\FixedCountProductIndexer;
use Symfony\Component\Console\Command\Command;

final class SeedWaitTest extends SeedCommandTestCase
{
    public function testIndexThatDoesNotCatchUpFailsWithBothCounts(): void
    {
        $tester = $this->tester(new FixedCountProductIndexer([3]));

        $exitCode = $tester->execute(['--timeout' => '0.2']);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertIsString($display);
        self::assertStringContainsString('10', $display);
        self::assertStringContainsString('3', $display);
        self::assertStringContainsString('worker', $display);
        self::assertStringNotContainsString('indexed: 10', $display);
    }

    public function testIndexThatCatchesUpEndsWithSummary(): void
    {
        $indexer = new FixedCountProductIndexer([0, 4, 10]);
        $tester = $this->tester($indexer);

        $exitCode = $tester->execute(['--timeout' => '5']);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('categories: 5, products: 10, indexed: 10', $tester->getDisplay());
        self::assertSame(3, $indexer->polls);
        self::assertSame(1, $this->reset->resets);
    }

    public function testUnavailableIndexIsReportedAsFailureAfterTheTimeout(): void
    {
        $tester = $this->tester(new class implements \App\Catalog\Application\Port\ProductIndexer {
            public function index(\App\Catalog\Application\Search\ProductDocument $document): \App\Catalog\Application\Search\IndexOutcome
            {
                throw new \LogicException();
            }

            public function countDocuments(): int
            {
                throw new \App\Catalog\Application\Search\SearchUnavailable('Elasticsearch is down.');
            }
        });

        $exitCode = $tester->execute(['--timeout' => '0.1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Elasticsearch is down.', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }
}
