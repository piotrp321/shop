<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\SyncStockRecords;
use App\Inventory\Application\Command\SyncStockRecordsHandler;
use App\Inventory\Application\Port\DirectoryPage;
use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Application\Port\StockRecords;
use App\Inventory\Application\StockRecords\StockRecordsSynced;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncStockRecordsHandlerTest extends TestCase
{
    private const string TENT = '10000000-0000-4000-8000-000000000001';
    private const string KAYAK = '10000000-0000-4000-8000-000000000002';
    private const string UNKNOWN = '10000000-0000-4000-8000-0000000000ff';
    private const string TENT_GREEN = '20000000-0000-4000-8000-000000000001';
    private const string TENT_RED = '20000000-0000-4000-8000-000000000002';
    private const string KAYAK_DEFAULT = '20000000-0000-4000-8000-000000000003';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $journal;
    private SyncStockRecordsHandler $handler;

    protected function setUp(): void
    {
        $this->journal = new \ArrayObject();
        $this->handler = new SyncStockRecordsHandler(
            new JournalingStockRecords($this->journal),
            new JournalingVariantDirectory($this->journal, [self::TENT => [self::TENT_GREEN, self::TENT_RED], self::KAYAK => [self::KAYAK_DEFAULT]]),
        );
    }

    /**
     * The variants are read once the products are locked: a sync that read older variants before a newer sync
     * committed would otherwise write them over the newer records.
     */
    public function testTheVariantsAreReadUnderTheLocksOfTheProductsAndThenWritten(): void
    {
        $synced = ($this->handler)(new SyncStockRecords([self::KAYAK, self::TENT]));

        self::assertSame(
            [
                'lock '.self::KAYAK.' '.self::TENT,
                'read '.self::KAYAK.' '.self::TENT,
                'ensure '.self::KAYAK.': '.self::KAYAK_DEFAULT.'; '.self::TENT.': '.self::TENT_GREEN.' '.self::TENT_RED,
                'remove '.self::KAYAK.': '.self::KAYAK_DEFAULT.'; '.self::TENT.': '.self::TENT_GREEN.' '.self::TENT_RED,
            ],
            $this->journal->getArrayCopy(),
        );
        self::assertEquals(new StockRecordsSynced(2, 3, 1), $synced);
    }

    public function testProductsCatalogDoesNotHaveAreLeftOutAndWithoutAnyNothingIsWritten(): void
    {
        $synced = ($this->handler)(new SyncStockRecords([self::UNKNOWN]));

        self::assertSame(['lock '.self::UNKNOWN, 'read '.self::UNKNOWN], $this->journal->getArrayCopy());
        self::assertEquals(new StockRecordsSynced(0, 0, 0), $synced);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidProducts(): iterable
    {
        yield 'none' => [[]];
        yield 'more than 100' => [array_map(static fn (int $i): string => \sprintf('10000000-0000-4000-8000-%012d', $i), range(1, 101))];
        yield 'not a UUID' => [['tent']];
    }

    /**
     * @param list<string> $productIds
     */
    #[DataProvider('invalidProducts')]
    public function testInvalidProductsAreRefusedBeforeAnyLock(array $productIds): void
    {
        try {
            ($this->handler)(new SyncStockRecords($productIds));
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->journal->getArrayCopy());
    }
}

/**
 * Writes every call to the journal; ensure() writes every variant, removeEmptyOfRemovedVariants() removes one.
 */
final readonly class JournalingStockRecords implements StockRecords
{
    /**
     * @param \ArrayObject<int, string> $journal
     */
    public function __construct(private \ArrayObject $journal)
    {
    }

    public function lockProducts(array $productIds): void
    {
        $this->journal[] = 'lock '.implode(' ', array_map(static fn (ProductId $id): string => $id->toString(), $productIds));
    }

    public function ensure(array $variantsByProduct): int
    {
        $this->journal[] = 'ensure '.self::describe($variantsByProduct);

        return array_sum(array_map(\count(...), $variantsByProduct));
    }

    public function removeEmptyOfRemovedVariants(array $variantsByProduct): int
    {
        $this->journal[] = 'remove '.self::describe($variantsByProduct);

        return 1;
    }

    /**
     * @param array<string, list<VariantId>> $variantsByProduct
     */
    private static function describe(array $variantsByProduct): string
    {
        $parts = [];
        foreach ($variantsByProduct as $productId => $variants) {
            $parts[] = $productId.': '.implode(' ', array_map(static fn (VariantId $id): string => $id->toString(), $variants));
        }

        return implode('; ', $parts);
    }
}

/**
 * Writes every read of variants to the journal.
 */
final readonly class JournalingVariantDirectory implements ProductDirectory
{
    /**
     * @param \ArrayObject<int, string>   $journal
     * @param array<string, list<string>> $variants product id => variant ids
     */
    public function __construct(private \ArrayObject $journal, private array $variants)
    {
    }

    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage): DirectoryPage
    {
        throw new \LogicException('Not used by the handler.');
    }

    public function variantsOf(array $productIds): array
    {
        $this->journal[] = 'read '.implode(' ', array_map(static fn (ProductId $id): string => $id->toString(), $productIds));
        $found = [];
        foreach ($productIds as $id) {
            if (isset($this->variants[$id->toString()])) {
                $found[$id->toString()] = array_map(static fn (string $variant): DirectoryVariant => new DirectoryVariant(VariantId::fromString($variant), ''), $this->variants[$id->toString()]);
            }
        }

        return $found;
    }
}
