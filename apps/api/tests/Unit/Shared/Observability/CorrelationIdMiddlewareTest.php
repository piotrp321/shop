<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Shared\Infrastructure\Observability\CorrelationIdMiddleware;
use App\Shared\Infrastructure\Observability\CorrelationIdStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdMiddlewareTest extends TestCase
{
    private const string FROM_THE_STAMP = '0198a1f0-0000-7000-8000-0000000000a1';
    private const string CURRENT = '0198a1f0-0000-7000-8000-0000000000c1';

    private CorrelationId $correlationId;
    private CorrelationIdMiddleware $middleware;

    protected function setUp(): void
    {
        $this->correlationId = new CorrelationId();
        $this->middleware = new CorrelationIdMiddleware($this->correlationId);
    }

    public function testAMessageSentWithoutAStampGetsTheCurrentIdentifier(): void
    {
        $this->correlationId->set(self::CURRENT);

        $seen = $this->through(new Envelope(new \stdClass()));

        self::assertSame(self::CURRENT, $seen['envelope']->last(CorrelationIdStamp::class)?->id);
        self::assertSame(self::CURRENT, $this->correlationId->current());
    }

    public function testAMessageSentWithoutAStampAndWithoutACurrentIdentifierGetsANewOne(): void
    {
        $seen = $this->through(new Envelope(new \stdClass()));

        $id = $seen['envelope']->last(CorrelationIdStamp::class)?->id;
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
    }

    public function testAStampOfTheMessageIsKeptOnSending(): void
    {
        $this->correlationId->set(self::CURRENT);

        $seen = $this->through(new Envelope(new \stdClass(), [new CorrelationIdStamp(self::FROM_THE_STAMP)]));

        self::assertSame([self::FROM_THE_STAMP], array_map(static fn (CorrelationIdStamp $stamp): string => $stamp->id, $seen['envelope']->all(CorrelationIdStamp::class)));
    }

    public function testAReceivedMessageIsHandledUnderTheIdentifierOfItsStampAndTheOldOneIsRestored(): void
    {
        $this->correlationId->set(self::CURRENT);

        $seen = $this->through(new Envelope(new \stdClass(), [new ReceivedStamp('events'), new CorrelationIdStamp(self::FROM_THE_STAMP)]));

        self::assertSame(self::FROM_THE_STAMP, $seen['during']);
        self::assertSame(self::CURRENT, $this->correlationId->current());
    }

    public function testAReceivedMessageIsHandledAndTheProcessIsLeftWithoutAnIdentifierWhenItHadNone(): void
    {
        $seen = $this->through(new Envelope(new \stdClass(), [new ReceivedStamp('events'), new CorrelationIdStamp(self::FROM_THE_STAMP)]));

        self::assertSame(self::FROM_THE_STAMP, $seen['during']);
        self::assertNull($this->correlationId->current());
    }

    public function testAReceivedMessageWithoutAStampGetsANewIdentifierForTheTimeOfHandling(): void
    {
        $seen = $this->through(new Envelope(new \stdClass(), [new ReceivedStamp('events')]));

        \assert(null !== $seen['during']);
        self::assertTrue(Uuid::isValid($seen['during']));
        self::assertNull($this->correlationId->current());
    }

    public function testTheIdentifierIsRestoredWhenTheHandlerThrows(): void
    {
        $this->correlationId->set(self::CURRENT);
        $envelope = new Envelope(new \stdClass(), [new ReceivedStamp('events'), new CorrelationIdStamp(self::FROM_THE_STAMP)]);
        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \DomainException('handler failed');
            }
        };

        try {
            $this->middleware->handle($envelope, new StackMiddleware($failing));
            self::fail('The exception of the handler must pass through.');
        } catch (\DomainException $e) {
            self::assertSame('handler failed', $e->getMessage());
        }

        self::assertSame(self::CURRENT, $this->correlationId->current());
    }

    /**
     * @return array{envelope: Envelope, during: ?string} the envelope that reached the next middleware and the
     *                                                    identifier that was current there
     */
    private function through(Envelope $envelope): array
    {
        $correlationId = $this->correlationId;
        $next = new class($correlationId) implements MiddlewareInterface {
            public ?Envelope $envelope = null;
            public ?string $duringHandling = null;

            public function __construct(private readonly CorrelationId $correlationId)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->envelope = $envelope;
                $this->duringHandling = $this->correlationId->current();

                return $envelope;
            }
        };

        $this->middleware->handle($envelope, new StackMiddleware($next));

        \assert($next->envelope instanceof Envelope);

        return ['envelope' => $next->envelope, 'during' => $next->duringHandling];
    }
}
