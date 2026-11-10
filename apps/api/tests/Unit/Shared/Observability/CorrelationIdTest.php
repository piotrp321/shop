<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\CorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdTest extends TestCase
{
    public function testThereIsNoCurrentIdentifierUntilOneIsStarted(): void
    {
        self::assertNull((new CorrelationId())->current());
    }

    public function testStartingGivesAUuidV7AndMakesItCurrent(): void
    {
        $correlationId = new CorrelationId();

        $started = $correlationId->start();

        self::assertTrue(Uuid::isValid($started));
        self::assertSame('7', $started[14]);
        self::assertSame($started, $correlationId->current());
    }

    public function testEachStartGivesANewIdentifier(): void
    {
        $correlationId = new CorrelationId();

        self::assertNotSame($correlationId->start(), $correlationId->start());
    }

    public function testSetReplacesAndNullClearsTheCurrentIdentifier(): void
    {
        $correlationId = new CorrelationId();

        $correlationId->set('0198a1f0-0000-7000-8000-0000000000a1');
        self::assertSame('0198a1f0-0000-7000-8000-0000000000a1', $correlationId->current());

        $correlationId->set(null);
        self::assertNull($correlationId->current());
    }

    public function testResetClearsTheIdentifierSoALongRunningProcessDoesNotCarryItOver(): void
    {
        $correlationId = new CorrelationId();
        $correlationId->start();

        $correlationId->reset();

        self::assertNull($correlationId->current());
    }
}
