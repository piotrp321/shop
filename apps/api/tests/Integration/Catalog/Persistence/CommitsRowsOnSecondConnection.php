<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\After;

/**
 * Lets a test play the competing request of a creation race: it commits a row on a second connection (outside the
 * DAMA transaction of the test) at the moment the repository has read the aggregate and is about to insert it.
 * Committed rows are deleted after the test.
 */
trait CommitsRowsOnSecondConnection
{
    private ?Connection $secondConnection = null;
    /** @var list<array{string, string}> table and id of every committed row, in insertion order */
    private array $committedRows = [];

    /**
     * @param array<string, string> $row column => value, with an `id` column
     */
    private function commitRow(EntityManagerInterface $entityManager, string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->secondConnection($entityManager)->executeStatement(
            \sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            array_values($row),
        );
        $this->committedRows[] = [$table, $row['id']];
    }

    /**
     * @param array<string, string> $row
     */
    private function commitRowBeforeNextInsert(EntityManagerInterface $entityManager, string $table, array $row): void
    {
        $listener = new class(function () use ($entityManager, $table, $row): void {
            $this->commitRow($entityManager, $table, $row);
        }) {
            public function __construct(private \Closure $commit)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                ($this->commit)();
            }
        };
        $eventManager = $entityManager->getEventManager();
        \assert($eventManager instanceof EventManager);
        $eventManager->addEventListener(Events::prePersist, $listener);
    }

    private function committedValue(EntityManagerInterface $entityManager, string $sql, string $id): mixed
    {
        return $this->secondConnection($entityManager)->fetchOne($sql, [$id]);
    }

    private function secondConnection(EntityManagerInterface $entityManager): Connection
    {
        // A plain DBAL configuration, so the DAMA static connection driver is not applied.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $entityManager->getConnection()->getParams();

        return $this->secondConnection ??= DriverManager::getConnection($params, new DbalConfiguration());
    }

    #[After]
    protected function deleteCommittedRows(): void
    {
        if (null === $this->secondConnection) {
            return;
        }
        foreach (array_reverse($this->committedRows) as [$table, $id]) {
            $this->secondConnection->executeStatement(\sprintf('DELETE FROM %s WHERE id = ?', $table), [$id]);
        }
        $this->secondConnection->close();
        $this->secondConnection = null;
        $this->committedRows = [];
    }
}
