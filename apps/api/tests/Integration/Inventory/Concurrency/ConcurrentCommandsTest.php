<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Concurrency;

use App\Tests\Integration\Inventory\IndependentConnections;
use App\Tests\Support\Concurrency\KillOwnProcess;
use App\Tests\Support\TestMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The harness of the concurrency tests: what a participant's command threw reaches the parent, and a participant
 * that dies without an outcome fails the run instead of being counted.
 */
final class ConcurrentCommandsTest extends KernelTestCase
{
    use IndependentConnections;

    public function testTheExceptionOfEveryParticipantReachesTheParentUnwrapped(): void
    {
        $outcomes = ConcurrentCommands::run($this->independentConnection(), array_fill(0, 3, new TestMessage('other')));

        self::assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame(\RuntimeException::class, $outcome->kind);
            self::assertSame('internal secret', $outcome->message);
            self::assertNull($outcome->result);
        }
    }

    public function testAParticipantDyingWithoutAnOutcomeFailsTheRun(): void
    {
        $control = $this->independentConnection();

        try {
            ConcurrentCommands::run($control, [new TestMessage('other'), new KillOwnProcess()]);
            self::fail('Expected the run to fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Participant 1 left no outcome.', $failure->getMessage());
        }

        // The barrier was released: the control connection can take it again at once.
        self::assertSame(0, $control->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = CAST(6782 AS int)::oid AND pid = pg_backend_pid()"));
    }
}
