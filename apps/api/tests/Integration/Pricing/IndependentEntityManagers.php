<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\After;

/**
 * Entity managers on their own connections, outside the DAMA transaction of the test, so a test can play two
 * requests that commit. Pricing rows of the products named in $committedVariantIds are deleted after the test.
 */
trait IndependentEntityManagers
{
    /** @var list<EntityManagerInterface> */
    private array $independentManagers = [];
    /** @var list<string> */
    private array $committedVariantIds = [];
    /** @var list<string> SQL run on a fresh connection after the test, before the rows are deleted */
    private array $cleanupStatements = [];

    private function independentManager(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof ManagerRegistry);
        $kernelManager = $registry->getManager();
        \assert($kernelManager instanceof EntityManagerInterface);

        $manager = new EntityManager($this->independentConnection(), $kernelManager->getConfiguration());
        $this->independentManagers[] = $manager;

        return $manager;
    }

    private function independentConnection(): Connection
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof ManagerRegistry);
        $kernelManager = $registry->getManager();
        \assert($kernelManager instanceof EntityManagerInterface);
        // A plain DBAL configuration, so the DAMA static connection driver is not applied.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelManager->getConnection()->getParams();

        return DriverManager::getConnection($params, new DbalConfiguration());
    }

    #[After]
    protected function deleteCommittedPricingRows(): void
    {
        if ([] === $this->independentManagers && [] === $this->cleanupStatements) {
            return;
        }
        $cleanup = $this->independentConnection();
        // A lock left by a broken test never hangs the suite.
        $cleanup->executeStatement("SET lock_timeout = '5s'");
        foreach ($this->cleanupStatements as $statement) {
            $cleanup->executeStatement($statement);
        }
        foreach ($this->committedVariantIds as $id) {
            $cleanup->executeStatement('DELETE FROM pricing_price_history WHERE variant_id = ?', [$id]);
            $cleanup->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id = ?', [$id]);
        }
        $cleanup->close();
        foreach ($this->independentManagers as $manager) {
            $manager->getConnection()->close();
        }
        $this->independentManagers = [];
        $this->committedVariantIds = [];
        $this->cleanupStatements = [];
    }
}
