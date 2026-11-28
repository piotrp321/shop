<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search\AsyncRebuild;

use App\Catalog\Application\Command\FinalizeSearchRebuild;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\SearchRebuildStatus;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

/**
 * Criterion 7 of ASYNC-INDEXING on the real Doctrine transport indexing_finalize: the finalization is a row written in
 * the transaction of the move to `finalizing`, so a rolled-back move leaves no message and a committed one leaves
 * exactly one, which a worker hands to indexing.bus (the bus without a transaction).
 */
final class FinalizationTransactionTest extends MessagingKernelTestCase
{
    public function testTheFinalizationExistsExactlyWhenTheMoveIsCommitted(): void
    {
        $runs = self::getContainer()->get(SearchRebuildRuns::class);
        \assert($runs instanceof SearchRebuildRuns);
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM catalog_search_rebuild_run');
        $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'indexing_finalize'");
        $run = $runs->start('product', 'test_products_target', 'test_products_old', 10, new \DateTimeImmutable());

        $connection->beginTransaction();
        $runs->finishProduction($run, 0, new \DateTimeImmutable());
        $connection->rollBack();

        self::assertSame(SearchRebuildStatus::Producing, $runs->get($run)?->status);
        self::assertSame(0, $this->rows('indexing_finalize'));

        $runs->finishProduction($run, 0, new \DateTimeImmutable());

        self::assertSame(SearchRebuildStatus::Finalizing, $runs->get($run)->status);
        self::assertSame(1, $this->rows('indexing_finalize'));
        $envelopes = [...$this->transport('indexing_finalize')->get()];
        self::assertCount(1, $envelopes);
        self::assertInstanceOf(FinalizeSearchRebuild::class, $envelopes[0]->getMessage());
        $stamp = $envelopes[0]->last(BusNameStamp::class);
        self::assertInstanceOf(BusNameStamp::class, $stamp);
        self::assertSame('indexing.bus', $stamp->getBusName());
    }
}
