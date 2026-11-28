<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The indexes of the list of the products of a category in the panel: (primary_category_id, name, id) and
 * (primary_category_id, updated_at, id), so that the products of one category are read in the order of both sorts and a page
 * of a department is the first rows of each of its categories, merged. The drafts and the products without images have
 * the same two keys in a partial index of their own (a category with its drafts is read from the few drafts, not from all
 * the products of the category): the predicates are the ones of the code (`status = 'draft'`, `images = '[]'`), written as
 * the planner needs them to prove them.
 *
 * Built CONCURRENTLY, so writes of products go on while a large table is indexed. That cannot run in a transaction, so the
 * migration is not transactional and every statement commits on its own. A build that failed (or whose Job was stopped)
 * leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so a retried
 * Job `migrate` builds it anew. A valid index is left as it is.
 */
final class Version20261128100000IndexCatalogProductByCategory extends AbstractMigration
{
    private const array INDEXES = [
        'catalog_product_category_name_id_idx' => '(primary_category_id, name, id)',
        'catalog_product_category_updated_at_id_idx' => '(primary_category_id, updated_at, id)',
        'catalog_product_category_draft_name_id_idx' => "(primary_category_id, name, id) WHERE status = 'draft'",
        'catalog_product_category_draft_updated_at_id_idx' => "(primary_category_id, updated_at, id) WHERE status = 'draft'",
        'catalog_product_category_without_images_name_id_idx' => "(primary_category_id, name, id) WHERE images = '[]'::jsonb",
        'catalog_product_category_without_images_updated_at_id_idx' => "(primary_category_id, updated_at, id) WHERE images = '[]'::jsonb",
    ];

    public function getDescription(): string
    {
        return 'Indexes of catalog_product by category and sort key (whole, drafts, without images), built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $name => $definition) {
            if ($this->isInvalid($name)) {
                $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
            }
            $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON catalog_product '.$definition);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }

    private function isInvalid(string $name): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [$name],
        );
    }
}
