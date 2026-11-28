<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Timeout;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Tells {@see DatabaseTimeouts} what kind of process runs: a main request (with its route), a Messenger worker, a
 * command of the migrations or any other command.
 */
final readonly class DatabaseTimeoutSubscriber implements EventSubscriberInterface
{
    private const string MIGRATION_COMMANDS = 'doctrine:migrations:';

    public function __construct(private DatabaseTimeouts $timeouts)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the router (priority 32), which names the route.
            KernelEvents::REQUEST => ['onRequest', 16],
            KernelEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::TERMINATE => 'onCommandTerminate',
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = $event->getRequest()->attributes->get('_route');
        $this->timeouts->startRequest(\is_string($route) ? $route : null);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->timeouts->endRequest();
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();
        $this->timeouts->useBase(null !== $name && str_starts_with($name, self::MIGRATION_COMMANDS) ? DatabaseTimeouts::MIGRATION : DatabaseTimeouts::CONSOLE);
    }

    public function onCommandTerminate(ConsoleTerminateEvent $event): void
    {
        $this->timeouts->useBase(DatabaseTimeouts::CONSOLE);
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->timeouts->useBase(DatabaseTimeouts::WORKER);
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->timeouts->useBase(DatabaseTimeouts::CONSOLE);
    }
}
