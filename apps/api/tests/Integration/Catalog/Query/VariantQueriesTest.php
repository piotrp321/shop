<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\GetVariantSummaries;
use App\Catalog\Application\Query\InvalidVariantSummariesQuery;
use App\Catalog\Application\Query\ListProductVariants;
use App\Catalog\Application\Query\ListVariantIdentities;
use App\Catalog\Application\Query\ProductVariantList;
use App\Catalog\Application\Query\VariantIdentity;
use App\Catalog\Application\Query\VariantInfo;
use App\Catalog\Application\Query\VariantSummary;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Catalog queries about variants that Pricing, Inventory and Cart ask, on shop_test (rolled back after each
 * test). Every variant id differs from the id of its product.
 */
final class VariantQueriesTest extends KernelTestCase
{
    use Factories;

    private const string BLACK_42 = 'c0000000-0000-4000-8000-000000000001';
    private const string WHITE_43 = 'a0000000-0000-4000-8000-000000000002';
    private const string BLACK_43 = 'b0000000-0000-4000-8000-000000000003';
    private const string HAT = 'd0000000-0000-4000-8000-000000000004';

    private MessageBusInterface $queryBus;
    private StatementCounter $counter;
    /** @var array{boots: Product, hat: Product} */
    private array $products;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $this->counter = $counter;

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $repository = new DoctrineProductRepository($entityManager);
        // The variants are saved in an order that is neither the order of their ids nor of the products.
        $boots = ProductFactory::createOne([
            'mainCategory' => $category,
            'name' => 'Buty Puls',
            'slug' => 'buty-puls',
            'imageAlts' => ['Biegaczka', 'Druga'],
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [
                ['id' => self::BLACK_42, 'values' => ['czarne', '42']],
                ['id' => self::WHITE_43, 'values' => ['Białe', '43']],
                ['id' => self::BLACK_43, 'values' => ['czarne', '43']],
            ],
        ]);
        $hat = ProductFactory::createOne([
            'mainCategory' => $category,
            'name' => 'Czapka',
            'slug' => 'czapka',
            'taxClass' => \App\Catalog\Domain\Product\TaxClassCode::FreezeDriedMeal,
            'variants' => [['id' => self::HAT, 'values' => []]],
        ]);
        $repository->save($boots);
        $repository->save($hat);
        $this->products = ['boots' => $boots, 'hat' => $hat];
    }

    public function testSummariesInTheOrderOfTheRequestWithAbsentAndRepeatedIdsHandled(): void
    {
        $absent = (string) Uuid::v7();

        $summaries = $this->summaries([self::HAT, $absent, strtoupper(self::WHITE_43), self::HAT, self::BLACK_42]);

        self::assertSame([self::HAT, self::WHITE_43, self::BLACK_42], array_map(static fn (VariantSummary $s): string => $s->variantId, $summaries));
        self::assertSame(['', 'Białe, 43', 'czarne, 42'], array_map(static fn (VariantSummary $s): string => $s->label, $summaries));
        self::assertSame(['Czapka', 'Buty Puls', 'Buty Puls'], array_map(static fn (VariantSummary $s): string => $s->name, $summaries));
        $bootsId = $this->products['boots']->id()->toString();
        self::assertSame([$this->products['hat']->id()->toString(), $bootsId, $bootsId], array_map(static fn (VariantSummary $s): string => $s->productId, $summaries));
        self::assertNull($summaries[0]->image);

        $image = $summaries[1]->image;
        self::assertNotNull($image);
        self::assertSame('Biegaczka', $image->alt, 'the main image is the first one');
        self::assertSame('listing', $image->size);
        self::assertSame([256, 384, 512, 768], array_column($image->variants['webp'], 'width'));
        $urls = self::getContainer()->get(ImageUrlGenerator::class);
        self::assertInstanceOf(ImageUrlGenerator::class, $urls);
        $key = $this->products['boots']->images()->main()?->key()->toString();
        self::assertIsString($key);
        self::assertSame($urls->url($key, ImageSize::Listing, 256, ImageFormat::Webp), $image->variants['webp'][0]['url']);
    }

    public function testSummariesDoNotTreatAProductIdAsAVariantId(): void
    {
        self::assertSame([], $this->summaries([$this->products['hat']->id()->toString()]));
    }

    public function testSummariesOfAHundredIdsAreReadWithOneStatement(): void
    {
        $ids = [self::BLACK_42, self::HAT];
        while (\count($ids) < GetVariantSummaries::MAX_VARIANTS) {
            $ids[] = (string) Uuid::v7();
        }

        $before = $this->counter->count();
        $summaries = $this->summaries($ids);

        self::assertSame(1, $this->counter->count() - $before);
        self::assertCount(2, $summaries);
    }

    public function testSummariesRefuseNoIdMoreThanAHundredAndANonUuid(): void
    {
        foreach ([[], array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 101)), ['not-a-uuid']] as $ids) {
            try {
                $this->summaries($ids);
                self::fail('Expected InvalidVariantSummariesQuery.');
            } catch (InvalidVariantSummariesQuery) {
            }
        }
    }

    public function testGetVariantReturnsItsProductAndTaxClass(): void
    {
        $info = $this->ask(new GetVariant(strtoupper(self::WHITE_43)));
        self::assertInstanceOf(VariantInfo::class, $info);
        self::assertSame([self::WHITE_43, $this->products['boots']->id()->toString(), 'standard'], [$info->variantId, $info->productId, $info->taxClass]);

        $hat = $this->ask(new GetVariant(self::HAT));
        self::assertInstanceOf(VariantInfo::class, $hat);
        self::assertSame('freeze_dried_meal', $hat->taxClass);
    }

    public function testGetVariantOfAnUnknownVariantOrOfAProductIdIsNullWithOneStatement(): void
    {
        $before = $this->counter->count();

        self::assertNull($this->ask(new GetVariant((string) Uuid::v7())));
        self::assertNull($this->ask(new GetVariant($this->products['boots']->id()->toString())));

        self::assertSame(2, $this->counter->count() - $before);
    }

    public function testProductVariantsWithAxesAndLabelsInThePositionOrder(): void
    {
        $lists = $this->variantLists([$this->products['hat']->id()->toString(), (string) Uuid::v7(), $this->products['boots']->id()->toString()]);

        self::assertCount(2, $lists);
        self::assertSame($this->products['hat']->id()->toString(), $lists[0]->productId);
        self::assertSame([], $lists[0]->variantAxes);
        self::assertSame([[self::HAT, '']], array_map(static fn ($v): array => [$v->variantId, $v->label], $lists[0]->variants));
        self::assertSame($this->products['boots']->id()->toString(), $lists[1]->productId);
        self::assertSame(['Kolor', 'Rozmiar'], $lists[1]->variantAxes);
        self::assertSame(
            [[self::BLACK_42, 'czarne, 42'], [self::WHITE_43, 'Białe, 43'], [self::BLACK_43, 'czarne, 43']],
            array_map(static fn ($v): array => [$v->variantId, $v->label], $lists[1]->variants),
        );
    }

    public function testProductVariantsOfAHundredProductsAreReadWithOneStatement(): void
    {
        $ids = [$this->products['boots']->id()->toString()];
        while (\count($ids) < ListProductVariants::MAX_PRODUCTS) {
            $ids[] = (string) Uuid::v7();
        }

        $before = $this->counter->count();
        $lists = $this->variantLists($ids);

        self::assertSame(1, $this->counter->count() - $before);
        self::assertCount(1, $lists);
    }

    public function testProductVariantsRefuseNoIdAndMoreThanAHundred(): void
    {
        foreach ([[], array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 101))] as $ids) {
            try {
                $this->variantLists($ids);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testIdentitiesCarryKeyAndTaxClassInBatchesByVariantId(): void
    {
        $before = $this->counter->count();
        $first = $this->identities(null, 2);
        self::assertSame(1, $this->counter->count() - $before);
        $second = $this->identities($first[1]->variantId, 2);
        $third = $this->identities($second[1]->variantId, 2);

        self::assertSame([self::WHITE_43, self::BLACK_43], array_map(static fn (VariantIdentity $i): string => $i->variantId, $first));
        self::assertSame([self::BLACK_42, self::HAT], array_map(static fn (VariantIdentity $i): string => $i->variantId, $second));
        self::assertSame([], $third);
        self::assertSame(
            ['buty-puls/biale-43', 'buty-puls/czarne-43', 'buty-puls/czarne-42', 'czapka/standard'],
            array_map(static fn (VariantIdentity $i): string => $i->key, [...$first, ...$second]),
        );
        self::assertSame(['standard', 'standard', 'standard', 'freeze_dried_meal'], array_map(static fn (VariantIdentity $i): string => $i->taxClass, [...$first, ...$second]));
    }

    public function testIdentitiesRefuseALimitOutOfRangeAndANonUuidCursor(): void
    {
        foreach ([[null, 0], [null, 1001], ['not-a-uuid', 10]] as [$after, $limit]) {
            try {
                $this->identities($after, $limit);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return list<VariantSummary>
     */
    private function summaries(array $ids): array
    {
        $result = $this->ask(new GetVariantSummaries($ids));
        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(VariantSummary::class, $result);

        /** @var list<VariantSummary> $result */
        return $result;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ProductVariantList>
     */
    private function variantLists(array $ids): array
    {
        $result = $this->ask(new ListProductVariants($ids));
        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(ProductVariantList::class, $result);

        /** @var list<ProductVariantList> $result */
        return $result;
    }

    /**
     * @return list<VariantIdentity>
     */
    private function identities(?string $after, int $limit): array
    {
        $result = $this->ask(new ListVariantIdentities($after, $limit));
        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(VariantIdentity::class, $result);

        /** @var list<VariantIdentity> $result */
        return $result;
    }

    private function ask(object $query): mixed
    {
        try {
            return $this->queryBus->dispatch($query)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            throw array_values($failure->getWrappedExceptions())[0];
        }
    }
}
