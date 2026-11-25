<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Concurrency;

use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\SkuAlreadyTaken;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Two products created at the same moment with the same two SKUs in the opposite order, each participant a process of its
 * own dispatching on command.bus (ConcurrentCommands of the Inventory tests): both pass the check of the handler, and the
 * unique index of the SKU decides (docs/features/product-data/spec.md, "Unikalność SKU przy równoległym zapisie"): one
 * write wins and the other is SkuAlreadyTaken (409), never a deadlock of the database (a 500) and never two variants with
 * one code. A trigger of the test holds every insert of a variant for half a second after the row is in the index, so
 * the interleaving that deadlocks writes in opposite orders is the one that happens, not a matter of luck.
 */
final class ProductSkuConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 3;

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
        $this->control->executeStatement('CREATE OR REPLACE FUNCTION catalog_test_hold_variant() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.5); RETURN NEW; END $$');
        $this->control->executeStatement('CREATE TRIGGER catalog_test_hold_variant AFTER INSERT ON catalog_product_variant FOR EACH ROW EXECUTE FUNCTION catalog_test_hold_variant()');
    }

    protected function tearDown(): void
    {
        $this->control->executeStatement('DROP TRIGGER IF EXISTS catalog_test_hold_variant ON catalog_product_variant');
        $this->control->executeStatement('DROP FUNCTION IF EXISTS catalog_test_hold_variant()');
        if ([] !== $this->categories) {
            $this->control->executeStatement('DELETE FROM catalog_product WHERE primary_category_id IN (?)', [$this->categories], [ArrayParameterType::STRING]);
            $this->control->executeStatement('DELETE FROM catalog_category WHERE id IN (?)', [$this->categories], [ArrayParameterType::STRING]);
        }
        $this->control->close();
        parent::tearDown();
    }

    public function testTwoCreationsWithTheSameSkusInTheOppositeOrderEndWithOneWinnerAndSkuTakenNeverADeadlock(): void
    {
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $category = $this->category();
            $first = 'ROUND'.$round.'-X';
            $second = 'ROUND'.$round.'-Y';

            $outcomes = ConcurrentCommands::run($this->control, [
                $this->create($category, 'a', [$first, $second]),
                $this->create($category, 'b', [$second, $first]),
            ]);

            $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
            sort($kinds);
            self::assertSame([SkuAlreadyTaken::class, Outcome::SUCCESS], $kinds, 'Round '.$round.': '.json_encode($outcomes, \JSON_THROW_ON_ERROR));
            self::assertSame(
                [1, 1],
                [
                    $this->control->fetchOne('SELECT count(*) FROM catalog_product_variant WHERE sku = ?', [$first]),
                    $this->control->fetchOne('SELECT count(*) FROM catalog_product_variant WHERE sku = ?', [$second]),
                ],
                'Round '.$round.': one variant of each code',
            );
            self::assertSame(1, $this->control->fetchOne('SELECT count(*) FROM catalog_product WHERE primary_category_id = ?', [$category]), 'Round '.$round.': one product');
        }
    }

    /**
     * @param list<string> $skus one variant per SKU, in this order
     */
    private function create(string $category, string $slug, array $skus): CreateProduct
    {
        $variants = [];
        foreach ($skus as $index => $sku) {
            $variants[] = ['id' => Uuid::v7()->toRfc4122(), 'values' => ['W'.$index], 'sku' => $sku, 'ean' => ''];
        }

        return new CreateProduct(Uuid::v7()->toRfc4122(), 'Produkt '.$slug, $slug.'-'.substr($category, -8), '', '', '', [], $category, [], 'standard', [], ['Rozmiar'], $variants, ProductStatus::Draft, 'staff-sub-1');
    }

    private function category(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->control->executeStatement('INSERT INTO catalog_category (id, name, slug, path) VALUES (?, ?, ?, ?)', [$id, 'Kategoria SKU', 'sku-'.substr($id, -12), '/'.$id.'/']);
        $this->categories[] = $id;

        return $id;
    }
}
