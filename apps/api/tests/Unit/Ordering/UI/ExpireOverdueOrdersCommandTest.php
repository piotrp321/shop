<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\UI;

use App\Ordering\Application\Command\ExpireOverdueOrder;
use App\Ordering\Application\OverdueOrders;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\UI\Console\ExpireOverdueOrdersCommand;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryOverdueOrderIds;
use App\Tests\Unit\Ordering\SpyLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * ordering:expire-overdue on a bus that answers as told: a version conflict of an order is retried once on the order
 * read anew (the state of the ORM forgotten before each attempt), a second conflict is the failure of that order
 * (spec: docs/features/async-reliability/spec.md, W5).
 */
final class ExpireOverdueOrdersCommandTest extends TestCase
{
    private const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-000000000002';

    private SpyLogger $logger;
    private int $clears = 0;
    /** @var list<string> the orders of every dispatch */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    public function testAVersionConflictIsRetriedOnceOnAFreshRead(): void
    {
        $bus = $this->bus([self::ORDER => [new OrderModifiedConcurrently(), true]]);
        $tester = $this->tester($bus, [self::ORDER]);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame([self::ORDER, self::ORDER], $this->dispatched);
        self::assertSame(2, $this->clears, 'the ORM forgets before each attempt');
        self::assertStringContainsString('Expired 1 overdue order.', $tester->getDisplay());
        self::assertSame([], $this->logger->records);
    }

    public function testASecondConflictIsTheFailureOfTheOrderAndTheOthersGoOn(): void
    {
        $bus = $this->bus([self::ORDER => [new OrderModifiedConcurrently(), new OrderModifiedConcurrently()], self::OTHER => [true]]);
        $tester = $this->tester($bus, [self::ORDER, self::OTHER]);

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());

        self::assertSame([self::ORDER, self::ORDER, self::OTHER], $this->dispatched);
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0][0]);
        self::assertSame(self::ORDER, $this->logger->records[0][2]['orderId'] ?? null);
        self::assertInstanceOf(OrderModifiedConcurrently::class, $this->logger->records[0][2]['exception'] ?? null);
    }

    /**
     * @param array<string, list<bool|\Throwable>> $answers what each dispatch of the order gives, in turn
     */
    private function bus(array $answers): MessageBusInterface
    {
        $answer = function (ExpireOverdueOrder $message) use (&$answers): bool|\Throwable {
            $this->dispatched[] = $message->orderId;

            return array_shift($answers[$message->orderId]) ?? throw new \LogicException('No answer left.');
        };

        return new class($answer) implements MessageBusInterface {
            /**
             * @param \Closure(ExpireOverdueOrder): (bool|\Throwable) $answer
             */
            public function __construct(private readonly \Closure $answer)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof ExpireOverdueOrder);
                $answer = ($this->answer)($message);
                $envelope = new Envelope($message);
                if ($answer instanceof \Throwable) {
                    throw new HandlerFailedException($envelope, [$answer]);
                }

                return $envelope->with(new HandledStamp($answer, 'handler'));
            }
        };
    }

    /**
     * @param list<string> $orderIds overdue, oldest first
     */
    private function tester(MessageBusInterface $bus, array $orderIds): CommandTester
    {
        $ids = new InMemoryOverdueOrderIds();
        foreach ($orderIds as $i => $orderId) {
            $ids->add($orderId, new \DateTimeImmutable('2026-11-05 10:00:00+00:00')->modify('+'.$i.' minutes'));
        }
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($manager);
        $application = new Application();
        $application->addCommand(new ExpireOverdueOrdersCommand(new OverdueOrders($ids, new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 13:00:00+00:00')), 600), $bus, $doctrine, $this->logger));

        return new CommandTester($application->find('ordering:expire-overdue'));
    }
}
