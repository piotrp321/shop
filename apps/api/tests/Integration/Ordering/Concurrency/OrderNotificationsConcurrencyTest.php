<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Ordering\Application\Port\NotificationClaim;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalOrderNotifications;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sending lease on PostgreSQL under concurrency: every participant claims the same notification from a process
 * and a connection of its own, all at once. The orders commit, so they are deleted after the test.
 */
final class OrderNotificationsConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    private const int PARTICIPANTS = 8;

    private Connection $control;
    /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} */
    private array $params;
    /** @var list<string> */
    private array $orders = [];

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->params = $params;
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
    }

    protected function tearDown(): void
    {
        OrderingFixture::deleteOrders($this->control, $this->orders);
        $this->control->close();
        parent::tearDown();
    }

    public function testOfConcurrentClaimsFromSeveralConnectionsExactlyOneIsClaimed(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 12:00:00+00:00');
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $orderId = OrderingFixture::order($this->control);
            $this->orders[] = $orderId;

            $outcomes = ParallelProcesses::run($this->control, array_fill(0, self::PARTICIPANTS, $this->claimant($orderId, $now)));

            sort($outcomes);
            self::assertSame(['claimed', ...array_fill(0, self::PARTICIPANTS - 1, 'inProgress until 2026-11-07T12:05:00+00:00')], $outcomes, 'round '.$round);
        }
    }

    /**
     * @return \Closure(): (\Closure(): string)
     */
    private function claimant(string $orderId, \DateTimeImmutable $now): \Closure
    {
        $params = $this->params;

        return static function () use ($params, $orderId, $now): \Closure {
            $notifications = new DbalOrderNotifications(DriverManager::getConnection($params, new DbalConfiguration()));

            return static fn (): string => self::describe($notifications->claim(OrderId::fromString($orderId), NotificationKind::Confirmation, $now));
        };
    }

    private static function describe(NotificationClaim $claim): string
    {
        return match (true) {
            $claim->isClaimed() => 'claimed',
            $claim->isAlreadySent() => 'alreadySent',
            default => 'inProgress until '.$claim->heldUntil()?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        };
    }
}
