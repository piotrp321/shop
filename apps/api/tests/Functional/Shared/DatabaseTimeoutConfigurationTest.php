<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Infrastructure\Persistence\Timeout\DatabaseTimeouts;
use App\Shared\Infrastructure\Persistence\Timeout\Timeouts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Routing\RouterInterface;

/**
 * The limits of the application as configured (config/services.yaml, `app.database_timeouts`): the values of the
 * decision of the owner of 2026-11-27 and what each kind of process sees on its connection (spec:
 * docs/features/runtime-config/spec.md, "Limity czasu w bazie").
 */
final class DatabaseTimeoutConfigurationTest extends KernelTestCase
{
    public function testTheLimitsOfEachKindOfProcess(): void
    {
        self::bootKernel();
        $timeouts = self::getContainer()->get(DatabaseTimeouts::class);
        \assert($timeouts instanceof DatabaseTimeouts);

        self::assertEquals(new Timeouts(0, 0, 0), $timeouts->current(), 'a command of the console');
        $timeouts->startRequest('catalog_product_list');
        self::assertEquals(new Timeouts(3, 60, 0), $timeouts->current(), 'an HTTP request');
        $timeouts->startRequest('catalog_backoffice_product_list');
        self::assertEquals(new Timeouts(90, 60, 0), $timeouts->current(), 'an endpoint with the exception');
        $timeouts->endRequest();
        $timeouts->useBase(DatabaseTimeouts::WORKER);
        self::assertEquals(new Timeouts(60, 60, 0), $timeouts->current(), 'a worker');
        $timeouts->useBase(DatabaseTimeouts::MIGRATION);
        self::assertEquals(new Timeouts(0, 0, 5), $timeouts->current(), 'a migration');
    }

    public function testEveryRouteWithTheExceptionExistsAndNoTestRouteIsAmongThem(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get('router');
        \assert($routes instanceof RouterInterface);
        $longRoutes = self::getContainer()->getParameter('app.database_timeouts.long_routes');
        \assert(\is_array($longRoutes));

        self::assertNotSame([], $longRoutes);
        foreach ($longRoutes as $name) {
            \assert(\is_string($name));
            self::assertNotNull($routes->getRouteCollection()->get($name), \sprintf('the route "%s" of the exceptions does not exist', $name));
            self::assertStringStartsNotWith('test_', $name);
        }
    }

    public function testACommandOfTheConsoleSeesNoLimit(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $tester->run(['command' => 'dbal:run-sql', 'sql' => "SELECT 'statement=' || (SELECT setting FROM pg_settings WHERE name = 'statement_timeout') || ' idle=' || (SELECT setting FROM pg_settings WHERE name = 'idle_in_transaction_session_timeout') || ' lock=' || (SELECT setting FROM pg_settings WHERE name = 'lock_timeout')"]);
        self::assertStringContainsString('statement=0 idle=0 lock=0', $tester->getDisplay());
    }

    public function testAMigrationCommandSeesTheLockTimeoutOnItsConnection(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        $dispatcher->dispatch(new ConsoleCommandEvent(new Command('doctrine:migrations:migrate'), new ArrayInput([]), new NullOutput()));

        self::assertSame('5000', $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'lock_timeout'"));
        self::assertSame('0', $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'"));
        $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('doctrine:migrations:migrate'), new ArrayInput([]), new NullOutput(), 0));
        self::assertSame('0', $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'lock_timeout'"));
    }

    public function testAWorkerSeesItsLimitsAndTheConsoleHasNoneAgainWhenItStops(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $worker = new Worker([], new MessageBus());

        $dispatcher->dispatch(new WorkerStartedEvent($worker));
        self::assertSame('60000', $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'"));
        self::assertSame('60000', $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'idle_in_transaction_session_timeout'"));

        $dispatcher->dispatch(new WorkerStoppedEvent($worker));
        self::assertSame('0', $connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'"));
    }
}
