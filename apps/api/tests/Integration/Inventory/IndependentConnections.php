<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\After;

/**
 * Connections of their own, outside the DAMA transaction of the test, so a test can play transactions that commit
 * or wait for each other. Inventory rows of the products and reservations named in $committedVariantIds and
 * $committedReservationKeys, and the Catalog products named in $committedCatalogProductIds, are deleted after the test.
 */
trait IndependentConnections
{
    /** @var list<Connection> */
    private array $independentConnections = [];
    /** @var list<string> */
    private array $committedVariantIds = [];
    /** @var list<string> */
    private array $committedReservationKeys = [];
    /** @var list<string> */
    private array $committedCatalogProductIds = [];

    private function independentConnection(): Connection
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        // A plain DBAL configuration, so the DAMA static connection driver is not applied.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $connection = DriverManager::getConnection($params, new DbalConfiguration());
        // A lock left by a broken test never hangs the suite.
        $connection->executeStatement("SET lock_timeout = '10s'");
        $this->independentConnections[] = $connection;

        return $connection;
    }

    /**
     * Runs the statement expecting PostgreSQL to refuse it for a lock it could not get (NOWAIT or lock_timeout).
     */
    private static function assertLockNotAvailable(\Closure $statement): void
    {
        try {
            $statement();
        } catch (DriverException $refused) {
            self::assertSame('55P03', $refused->getSQLState(), $refused->getMessage());

            return;
        }
        self::fail('Expected the statement to wait for a lock.');
    }

    #[After]
    protected function deleteCommittedInventoryRows(): void
    {
        if ([] === $this->independentConnections) {
            return;
        }
        foreach ($this->independentConnections as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        StockFixture::delete($this->independentConnections[0], $this->committedVariantIds, $this->committedReservationKeys);
        CatalogFixture::delete($this->independentConnections[0], $this->committedCatalogProductIds);
        foreach ($this->independentConnections as $connection) {
            $connection->close();
        }
        $this->independentConnections = [];
        $this->committedVariantIds = [];
        $this->committedReservationKeys = [];
        $this->committedCatalogProductIds = [];
    }
}
