<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\ReadModel;

use App\Ordering\Application\ReadModel\CustomerRebuild;
use App\Tests\Unit\Ordering\InMemoryCustomerSummariesRebuild;
use PHPUnit\Framework\TestCase;

/**
 * The customer part of ordering:read-models:rebuild: every customer in batches of 500 by id, each batch after the
 * last customer of the one before, with a summary and progress lines; a failure stops it.
 */
final class CustomerRebuildTest extends TestCase
{
    public function testRebuildsEveryCustomerInBatchesInTheOrderOfTheIdsAndReportsTheTotals(): void
    {
        $customers = [];
        for ($i = 1; $i <= 1_200; ++$i) {
            $customers[\sprintf('kc-%05d', $i)] = 2;
        }
        $rebuild = new InMemoryCustomerSummariesRebuild($customers);
        $lines = [];

        $summary = new CustomerRebuild($rebuild)->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame([[null, 'kc-00500'], ['kc-00500', 'kc-01000'], ['kc-01000', 'kc-01200']], $rebuild->rebuilt);
        self::assertSame([500, 500, 500, 500], $rebuild->sizes, 'one look more finds the end');
        self::assertSame('customers: 1200 customers rebuilt in 3 batches, 2400 orders', $summary);
        self::assertSame([], $lines, 'no progress line below 100 batches');
    }

    public function testWithoutCustomersNothingIsRebuilt(): void
    {
        $rebuild = new InMemoryCustomerSummariesRebuild([]);

        self::assertSame('customers: 0 customers rebuilt in 0 batches, 0 orders', new CustomerRebuild($rebuild)->run(static function (): void {}));
        self::assertSame([], $rebuild->rebuilt);
    }

    public function testReportsTheProgressEveryHundredBatches(): void
    {
        $customers = [];
        for ($i = 1; $i <= 100_500; ++$i) {
            $customers[\sprintf('kc-%06d', $i)] = 1;
        }
        $lines = [];

        new CustomerRebuild(new InMemoryCustomerSummariesRebuild($customers))->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame(['customers: 100 batches rebuilt, 50000 customers, up to kc-050000', 'customers: 200 batches rebuilt, 100000 customers, up to kc-100000'], $lines);
    }

    public function testAFailureStopsTheRebuildAndPassesOn(): void
    {
        $customers = [];
        for ($i = 1; $i <= 1_500; ++$i) {
            $customers[\sprintf('kc-%05d', $i)] = 1;
        }
        $rebuild = new InMemoryCustomerSummariesRebuild($customers, failingLast: 'kc-01000');

        try {
            new CustomerRebuild($rebuild)->run(static function (): void {});
            self::fail('Expected the failure of the batch.');
        } catch (\RuntimeException $failure) {
            self::assertSame('the database went away', $failure->getMessage());
        }
        self::assertSame([[null, 'kc-00500']], $rebuild->rebuilt);
    }
}
