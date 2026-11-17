<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CategoryReindexRun;
use App\Catalog\Application\Command\StartCategoryReindex;
use App\Catalog\Application\Command\StartCategoryReindexHandler;
use App\Catalog\Application\Event\CategoryTreeChanged;
use App\Catalog\Application\Event\CategoryTreeChangeKind;
use App\Catalog\Application\EventHandler\ReindexSubtreeOnCategoryTreeChange;
use App\Tests\Support\InMemory\RecordingMessageBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The start of the chain: a new name or a new parent of a category changes category_names and category_ancestor_ids
 * of the products of its subtree, so the consumer of CategoryTreeChanged starts a run; a new category, a deleted
 * (empty) one and a new place among the same siblings change no document. The start writes the run and sends its
 * first batch.
 */
final class CategoryReindexStartTest extends TestCase
{
    private const string CATEGORY = 'C0000000-0000-4000-8000-000000000001';

    /**
     * @return iterable<string, array{CategoryTreeChangeKind}>
     */
    public static function reindexingKinds(): iterable
    {
        yield 'revised (name, slug)' => [CategoryTreeChangeKind::Revised];
        yield 'moved (new parent)' => [CategoryTreeChangeKind::Moved];
    }

    /**
     * @return iterable<string, array{CategoryTreeChangeKind}>
     */
    public static function otherKinds(): iterable
    {
        yield 'created' => [CategoryTreeChangeKind::Created];
        yield 'reordered (same parent)' => [CategoryTreeChangeKind::Reordered];
        yield 'deleted' => [CategoryTreeChangeKind::Deleted];
    }

    #[DataProvider('reindexingKinds')]
    public function testARenameOrAMoveStartsTheReindexingOfTheSubtree(CategoryTreeChangeKind $kind): void
    {
        $bus = new RecordingMessageBus();

        new ReindexSubtreeOnCategoryTreeChange($bus)(self::event($kind));

        self::assertEquals([new StartCategoryReindex(strtolower(self::CATEGORY))], $bus->dispatched);
    }

    #[DataProvider('otherKinds')]
    public function testOtherChangesOfTheTreeReindexNothing(CategoryTreeChangeKind $kind): void
    {
        $bus = new RecordingMessageBus();

        new ReindexSubtreeOnCategoryTreeChange($bus)(self::event($kind));

        self::assertSame([], $bus->dispatched);
    }

    public function testAnEventThatNamesNoCategoryGoesToFailed(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        new ReindexSubtreeOnCategoryTreeChange(new RecordingMessageBus())(new CategoryTreeChanged('not-a-uuid', CategoryTreeChangeKind::Moved, new \DateTimeImmutable()));
    }

    public function testTheStartWritesARunExpectingTheFirstBatchAndSendsIt(): void
    {
        $runs = new InMemoryCategoryReindexRuns();
        $batches = new RecordingCategoryReindexBatches();

        new StartCategoryReindexHandler($runs, $batches)(new StartCategoryReindex(self::CATEGORY));

        self::assertCount(1, $runs->runs);
        $run = array_values($runs->runs)[0];
        self::assertEquals(new CategoryReindexRun($run->id, strtolower(self::CATEGORY), null, 0, false), $run);
        self::assertSame([[$run->id, 0]], $batches->sent);
    }

    private static function event(CategoryTreeChangeKind $kind): CategoryTreeChanged
    {
        return new CategoryTreeChanged(self::CATEGORY, $kind, new \DateTimeImmutable('2026-11-15T12:00:00+00:00'));
    }
}
