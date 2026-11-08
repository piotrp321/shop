<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrated test database and the Doctrine mapping describe the same schema of Ordering (what an empty
 * `doctrine:migrations:diff` checks), and the indexes of the list of the panel are there as the migration made them:
 * descending, on lower(email) (unknown to the mapping) and partial for the pending refunds.
 */
final class OrderingSchemaTest extends KernelTestCase
{
    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $entityManager = $this->entityManager();

        $updates = array_values(array_filter(
            new SchemaTool($entityManager)->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'ordering_'),
        ));

        self::assertSame([], $updates);
    }

    public function testTheListOfThePanelHasItsIndexes(): void
    {
        $indexes = $this->entityManager()->getConnection()->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'ordering_order' AND indexname IN ('ordering_order_placed_at_id_idx', 'ordering_order_status_placed_at_id_idx', 'ordering_order_lower_email_idx', 'ordering_order_pending_refund_idx') ORDER BY indexname",
        );

        self::assertSame([
            'ordering_order_lower_email_idx' => 'CREATE INDEX ordering_order_lower_email_idx ON public.ordering_order USING btree (lower((email)::text))',
            'ordering_order_pending_refund_idx' => 'CREATE INDEX ordering_order_pending_refund_idx ON public.ordering_order USING btree (refund_due_at, id) WHERE ((refund_amount IS NOT NULL) AND (refund_completed_at IS NULL))',
            'ordering_order_placed_at_id_idx' => 'CREATE INDEX ordering_order_placed_at_id_idx ON public.ordering_order USING btree (placed_at DESC, id DESC)',
            'ordering_order_status_placed_at_id_idx' => 'CREATE INDEX ordering_order_status_placed_at_id_idx ON public.ordering_order USING btree (status, placed_at DESC, id DESC)',
        ], $indexes);
    }

    public function testTheHistoryOfACustomerHasItsPartialIndex(): void
    {
        $definition = $this->entityManager()->getConnection()->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'ordering_order' AND indexname = 'ordering_order_customer_placed_at_id_idx'",
        );

        self::assertSame(
            "CREATE INDEX ordering_order_customer_placed_at_id_idx ON public.ordering_order USING btree (customer_id, placed_at DESC, id DESC) WHERE ((owner_type)::text = 'customer'::text)",
            $definition,
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
