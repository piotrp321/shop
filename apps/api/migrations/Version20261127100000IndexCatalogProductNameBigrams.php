<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The bigrams of the product name: the function catalog_name_bigrams(text) (the distinct two-character substrings of the
 * lowered text) and a GIN index on it. The product list of the panel uses it for a phrase that has no word of three
 * characters: pg_trgm extracts no selective trigram from such a pattern (`qx`, `#!#`, `a.b`), so the trigram index
 * returns a large part of the table, while the bigrams of the phrase are in few names. The index only narrows the
 * candidates; the exact condition `name ILIKE` is still checked on them.
 *
 * Built CONCURRENTLY, so writes of products go on while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is. The body of the function must not change once
 * an index exists: the index holds its results.
 */
final class Version20261127100000IndexCatalogProductNameBigrams extends AbstractMigration
{
    private const string INDEX = 'catalog_product_name_bigrams_idx';

    public function getDescription(): string
    {
        return 'Function catalog_name_bigrams(text) and a GIN index on the bigrams of catalog_product.name, built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // The length is taken of the lowered text too: lower() may change the number of characters.
        $this->addSql(
            'CREATE OR REPLACE FUNCTION catalog_name_bigrams(text) RETURNS text[] LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$'
            .' SELECT coalesce(array_agg(DISTINCT substr(lower($1), i, 2)), \'{}\'::text[])'
            .' FROM generate_series(1, greatest(length(lower($1)) - 1, 0)) AS i $$',
        );
        if ($this->isInvalid(self::INDEX)) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON catalog_product USING gin (catalog_name_bigrams(name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        $this->addSql('DROP FUNCTION IF EXISTS catalog_name_bigrams(text)');
    }

    private function isInvalid(string $name): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [$name],
        );
    }
}
