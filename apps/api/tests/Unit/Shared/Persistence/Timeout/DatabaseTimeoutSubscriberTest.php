<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Persistence\Timeout;

use App\Shared\Infrastructure\Persistence\Timeout\DatabaseTimeouts;
use App\Shared\Infrastructure\Persistence\Timeout\DatabaseTimeoutSubscriber;
use App\Shared\Infrastructure\Persistence\Timeout\Timeouts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;

final class DatabaseTimeoutSubscriberTest extends TestCase
{
    private DatabaseTimeouts $timeouts;
    private DatabaseTimeoutSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->timeouts = new DatabaseTimeouts(
            [
                DatabaseTimeouts::CONSOLE => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 0],
                DatabaseTimeouts::HTTP => ['statement' => 3, 'idle_in_transaction' => 60, 'lock' => 0],
                DatabaseTimeouts::HTTP_LONG => ['statement' => 90, 'idle_in_transaction' => 60, 'lock' => 0],
                DatabaseTimeouts::WORKER => ['statement' => 60, 'idle_in_transaction' => 60, 'lock' => 0],
                DatabaseTimeouts::MIGRATION => ['statement' => 0, 'idle_in_transaction' => 0, 'lock' => 5],
            ],
            ['long_route'],
            [],
        );
        $this->subscriber = new DatabaseTimeoutSubscriber($this->timeouts);
    }

    public function testTheMainRequestStartsWithTheLimitOfItsRouteAndTheTerminationEndsIt(): void
    {
        $request = new Request(attributes: ['_route' => 'long_route']);

        $this->subscriber->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertEquals(new Timeouts(90, 60, 0), $this->timeouts->current());

        $this->subscriber->onTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));
        self::assertEquals(new Timeouts(0, 0, 0), $this->timeouts->current());
    }

    public function testASubRequestDoesNotChangeTheLimit(): void
    {
        $request = new Request(attributes: ['_route' => 'long_route']);

        $this->subscriber->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST));

        self::assertEquals(new Timeouts(0, 0, 0), $this->timeouts->current());
    }

    public function testAWorkerHasItsLimitsFromItsStartToItsStop(): void
    {
        $worker = new Worker([], new \Symfony\Component\Messenger\MessageBus());

        $this->subscriber->onWorkerStarted(new WorkerStartedEvent($worker));
        self::assertEquals(new Timeouts(60, 60, 0), $this->timeouts->current());

        $this->subscriber->onWorkerStopped(new WorkerStoppedEvent($worker));
        self::assertEquals(new Timeouts(0, 0, 0), $this->timeouts->current());
    }

    public function testAMigrationCommandHasTheLockTimeoutAndAnyOtherCommandHasNoLimits(): void
    {
        foreach (['doctrine:migrations:migrate', 'doctrine:migrations:execute'] as $name) {
            $this->subscriber->onCommand($this->commandEvent($name));
            self::assertEquals(new Timeouts(0, 0, 5), $this->timeouts->current(), $name);
            $this->subscriber->onCommandTerminate(new ConsoleTerminateEvent(new Command($name), new ArrayInput([]), new NullOutput(), 0));
            self::assertEquals(new Timeouts(0, 0, 0), $this->timeouts->current());
        }

        foreach (['dbal:run-sql', 'catalog:reindex', 'demo-data:generate', 'app:backup'] as $name) {
            $this->subscriber->onCommand($this->commandEvent($name));
            self::assertEquals(new Timeouts(0, 0, 0), $this->timeouts->current(), $name);
        }
    }

    private function commandEvent(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }
}
