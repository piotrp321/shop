<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Persistence\Timeout;

use App\Shared\Infrastructure\Persistence\Timeout\DatabaseTimeouts;
use App\Shared\Infrastructure\Persistence\Timeout\Timeouts;
use PHPUnit\Framework\TestCase;

final class DatabaseTimeoutsTest extends TestCase
{
    private function timeouts(): DatabaseTimeouts
    {
        return new DatabaseTimeouts(
            [
                DatabaseTimeouts::CONSOLE => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 0],
                DatabaseTimeouts::HTTP => ['statement' => 3, 'idle_in_transaction' => 60, 'lock' => 0],
                DatabaseTimeouts::HTTP_LONG => ['statement' => 90, 'idle_in_transaction' => 60, 'lock' => 0],
                DatabaseTimeouts::WORKER => ['statement' => 60, 'idle_in_transaction' => 60, 'lock' => 0],
                DatabaseTimeouts::MIGRATION => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 5],
            ],
            ['catalog_backoffice_product_list'],
            ['test_long'],
        );
    }

    public function testAProcessThatIsNotARequestNorAWorkerHasNoLimits(): void
    {
        self::assertEquals(new Timeouts(0, 0, 0), $this->timeouts()->current());
    }

    public function testARequestHasTheLimitsOfHttpAndTheEndOfItGivesTheBaseBack(): void
    {
        $timeouts = $this->timeouts();

        $timeouts->startRequest('catalog_product_list');
        self::assertEquals(new Timeouts(3, 60, 0), $timeouts->current());

        $timeouts->endRequest();
        self::assertEquals(new Timeouts(0, 0, 0), $timeouts->current());
    }

    public function testARouteWithAnExceptionHasTheLongerLimitAndTheNextRequestDoesNotKeepIt(): void
    {
        $timeouts = $this->timeouts();

        $timeouts->startRequest('catalog_backoffice_product_list');
        self::assertEquals(new Timeouts(90, 60, 0), $timeouts->current());
        $timeouts->startRequest('test_long');
        self::assertEquals(new Timeouts(90, 60, 0), $timeouts->current(), 'a route of the additional list');
        $timeouts->endRequest();

        $timeouts->startRequest('catalog_product_list');
        self::assertEquals(new Timeouts(3, 60, 0), $timeouts->current());
        $timeouts->startRequest(null);
        self::assertEquals(new Timeouts(3, 60, 0), $timeouts->current(), 'a request that no route matched');
    }

    public function testTheBaseOfAWorkerAndOfAMigrationIsTheirOwnAndARequestLeavesItAsItWas(): void
    {
        $timeouts = $this->timeouts();

        $timeouts->useBase(DatabaseTimeouts::WORKER);
        self::assertEquals(new Timeouts(60, 60, 0), $timeouts->current());
        $timeouts->startRequest('catalog_product_list');
        $timeouts->endRequest();
        self::assertEquals(new Timeouts(60, 60, 0), $timeouts->current());

        $timeouts->useBase(DatabaseTimeouts::MIGRATION);
        self::assertEquals(new Timeouts(0, 0, 5), $timeouts->current());

        $timeouts->useBase(DatabaseTimeouts::CONSOLE);
        self::assertEquals(new Timeouts(0, 0, 0), $timeouts->current());
    }

    public function testAnUnknownProfileIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->timeouts()->useBase('forever');
    }

    public function testAMissingProfileInTheConfigurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseTimeouts([DatabaseTimeouts::CONSOLE => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 0]], [], []);
    }
}
