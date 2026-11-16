<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Concurrency;

use App\Catalog\Application\Command\MoveCategory;
use App\Catalog\Domain\Category\CategoryCycle;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Two moves at the same moment that together would form a cycle (A under B and B under A), each participant a process
 * of its own dispatching on command.bus (ConcurrentCommands of the Inventory tests). Both read version 1 and a tree
 * without a cycle; the lock of the tree makes the second wait for the first and read the tree it committed, so one
 * move wins and the other is a CategoryCycle. The categories commit, so they are deleted after the test.
 */
final class CategoryMoveConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private Connection $control;
    /** @var list<string> */
    private array $categories = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
    }

    protected function tearDown(): void
    {
        if ([] !== $this->categories) {
            // Children first: the parent of a category is a foreign key without a cascade.
            $this->control->executeStatement('DELETE FROM catalog_category WHERE id IN (?) AND parent_id IS NOT NULL', [$this->categories], [ArrayParameterType::STRING]);
            $this->control->executeStatement('DELETE FROM catalog_category WHERE id IN (?)', [$this->categories], [ArrayParameterType::STRING]);
        }
        $this->control->close();
        parent::tearDown();
    }

    public function testTwoMovesThatWouldFormACycleEndWithOneWinnerAndOneCycle(): void
    {
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $a = $this->root('a');
            $b = $this->root('b');

            $outcomes = ConcurrentCommands::run($this->control, [
                new MoveCategory($a, 1, $b, 0),
                new MoveCategory($b, 1, $a, 0),
            ]);

            $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
            sort($kinds);
            self::assertSame([CategoryCycle::class, Outcome::SUCCESS], $kinds, 'Round '.$round.': '.json_encode($outcomes, \JSON_THROW_ON_ERROR));

            // Exactly one of them is under the other, and the tree has no cycle.
            $parents = $this->control->fetchAllKeyValue('SELECT id, parent_id FROM catalog_category WHERE id IN (?, ?)', [$a, $b]);
            self::assertCount(1, array_filter($parents, static fn (mixed $parent): bool => null !== $parent), 'Round '.$round);
            $moved = null === $parents[$a] ? $b : $a;
            $stayed = $moved === $a ? $b : $a;
            self::assertSame('/'.$stayed.'/'.$moved.'/', $this->control->fetchOne('SELECT path FROM catalog_category WHERE id = ?', [$moved]));
            self::assertSame(2, $this->control->fetchOne('SELECT version FROM catalog_category WHERE id = ?', [$moved]));
            self::assertSame(1, $this->control->fetchOne('SELECT version FROM catalog_category WHERE id = ?', [$stayed]));
        }
    }

    private function root(string $prefix): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->control->executeStatement(
            'INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)',
            [$id, 'Kategoria '.$prefix, $prefix.'-'.substr($id, -12), '/'.$id.'/'],
        );
        $this->categories[] = $id;

        return $id;
    }
}
