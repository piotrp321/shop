<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The trigram indexes of the search of the customer list by a fragment of the email or of the name: the conditions of
 * DbalCustomerReader are lower(email) and lower(full_name), character for character, which is what lets the planner
 * use them (one bitmap scan of each, joined by OR). The extension pg_trgm is there since Version20261101130000.
 *
 * Built CONCURRENTLY, as in Version20261115140000IndexOrderSearch: the migration is not transactional, an INVALID
 * index of a name left by a failed build is dropped first, so a retried Job `migrate` builds it anew, and a valid one
 * is left as it is.
 */
final class Version20261116090100IndexCustomerSearch extends AbstractMigration
{
    private const array INDEXES = [
        'ordering_customer_email_trgm_idx' => 'USING gin (lower(email) gin_trgm_ops)',
        'ordering_customer_name_trgm_idx' => 'USING gin (lower(full_name) gin_trgm_ops)',
    ];

    public function getDescription(): string
    {
        return 'Trigram GIN indexes on the email and the name of ordering_customer for the search by fragment, built concurrently.';
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
            $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON ordering_customer '.$definition);
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
