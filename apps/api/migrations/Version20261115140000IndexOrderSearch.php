<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The one trigram index of the search of the order list by fragment, on the number, the email and the name together
 * with the separator U+001F between them (none of the three can hold a control character, so a fragment cannot
 * span two of them). The search condition of DbalBackofficeOrderReader is this expression, character for character,
 * which is what lets the planner use the index. All three columns are NOT NULL, so the concatenation is never NULL.
 *
 * Built CONCURRENTLY, so orders can be placed while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is. The extension pg_trgm is there since
 * Version20261101130000.
 */
final class Version20261115140000IndexOrderSearch extends AbstractMigration
{
    private const string INDEX = 'ordering_order_search_trgm_idx';

    public function getDescription(): string
    {
        return 'Trigram GIN index on the number, email and name of ordering_order for the search by fragment, built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($this->isInvalid(self::INDEX)) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX." ON ordering_order USING gin (lower(number || E'\\x1f' || email || E'\\x1f' || full_name) gin_trgm_ops)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }

    private function isInvalid(string $name): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [$name],
        );
    }
}
