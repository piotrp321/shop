<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\GetVariantHandler;
use App\Catalog\Application\Query\GetVariantSummaries;
use App\Catalog\Application\Query\GetVariantSummariesHandler;
use App\Catalog\Application\Query\InvalidVariantSummariesQuery;
use App\Catalog\Application\Query\ListProductVariants;
use App\Catalog\Application\Query\ListProductVariantsHandler;
use App\Catalog\Application\Query\ListVariantIdentities;
use App\Catalog\Application\Query\ListVariantIdentitiesHandler;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;

final class VariantQueriesTest extends TestCase
{
    private const string BOOTS = 'b0000000-0000-4000-8000-000000000001';
    private const string BOOTS_BLACK_42 = 'a0000000-0000-4000-8000-000000000001';
    private const string BOOTS_WHITE_43 = 'a0000000-0000-4000-8000-000000000002';
    private const string CAP = 'b0000000-0000-4000-8000-000000000002';
    private const string CAP_DEFAULT = 'a0000000-0000-4000-8000-000000000003';
    private const string ABSENT = 'a0000000-0000-4000-8000-0000000000ff';
    private const string DRAFT = 'b0000000-0000-4000-8000-000000000003';
    private const string DRAFT_M = 'a0000000-0000-4000-8000-000000000004';

    private FakeVariantSource $source;

    protected function setUp(): void
    {
        $this->source = new FakeVariantSource([
            [
                'id' => self::BOOTS, 'slug' => 'buty-puls', 'name' => 'Buty Puls', 'taxClass' => 'standard', 'axes' => ['Kolor', 'Rozmiar'],
                'mainImage' => ['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'Biegaczka'],
                'variants' => [
                    ['id' => self::BOOTS_BLACK_42, 'values' => ['czarne', '42']],
                    ['id' => self::BOOTS_WHITE_43, 'values' => ['Białe', '43']],
                ],
            ],
            [
                'id' => self::CAP, 'slug' => 'czapka', 'name' => 'Czapka', 'taxClass' => 'standard', 'axes' => [],
                'mainImage' => null,
                'variants' => [['id' => self::CAP_DEFAULT, 'values' => []]],
            ],
        ]);
    }

    public function testSummariesFollowTheOrderOfTheRequestWithAbsentAndRepeatedIdsHandled(): void
    {
        $summaries = $this->summaries()(new GetVariantSummaries([self::CAP_DEFAULT, self::ABSENT, strtoupper(self::BOOTS_WHITE_43), self::CAP_DEFAULT, self::BOOTS_BLACK_42]));

        self::assertSame([self::CAP_DEFAULT, self::BOOTS_WHITE_43, self::BOOTS_BLACK_42], array_map(static fn ($s): string => $s->variantId, $summaries));
        self::assertSame(['', 'Białe, 43', 'czarne, 42'], array_map(static fn ($s): string => $s->label, $summaries));
        self::assertSame([self::CAP, self::BOOTS, self::BOOTS], array_map(static fn ($s): string => $s->productId, $summaries));
        self::assertSame(['Czapka', 'Buty Puls', 'Buty Puls'], array_map(static fn ($s): string => $s->name, $summaries));
        self::assertNull($summaries[0]->image);
        self::assertSame('Biegaczka', $summaries[1]->image?->alt);
        self::assertSame('listing', $summaries[1]->image->size);
        self::assertSame(['summaries'], $this->source->calls);
    }

    public function testSummariesOfNoPresentVariantAreEmpty(): void
    {
        self::assertSame([], $this->summaries()(new GetVariantSummaries([self::ABSENT])));
    }

    public function testSummariesRefuseNoIdMoreThanAHundredAndANonUuid(): void
    {
        foreach ([[], array_map(static fn (int $i): string => \sprintf('a0000000-0000-4000-8000-%012d', $i), range(1, 101)), ['not-a-uuid']] as $ids) {
            try {
                $this->summaries()(new GetVariantSummaries($ids));
                self::fail('Expected InvalidVariantSummariesQuery.');
            } catch (InvalidVariantSummariesQuery $e) {
                self::assertContains(InvalidInput::class, class_implements($e));
                self::assertStringNotContainsString('not-a-uuid', $e->getMessage());
            }
        }
        self::assertSame([], $this->source->calls);
    }

    public function testSummariesAcceptExactlyAHundredIds(): void
    {
        $ids = array_map(static fn (int $i): string => \sprintf('a0000000-0000-4000-8000-%012d', $i), range(100, 199));

        self::assertSame([], $this->summaries()(new GetVariantSummaries($ids)));
        self::assertSame(['summaries'], $this->source->calls);
    }

    public function testGetVariantReturnsItsProductAndTaxClassOrNull(): void
    {
        $handler = new GetVariantHandler($this->source);

        $variant = $handler(new GetVariant(strtoupper(self::BOOTS_WHITE_43)));
        self::assertNotNull($variant);
        self::assertSame([self::BOOTS_WHITE_43, self::BOOTS, 'standard'], [$variant->variantId, $variant->productId, $variant->taxClass]);
        self::assertNull($handler(new GetVariant(self::ABSENT)));
    }

    public function testGetVariantOfANonUuidIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GetVariantHandler($this->source)(new GetVariant('not-a-uuid'));
    }

    public function testListProductVariantsFollowsTheRequestAndKeepsThePositionOrder(): void
    {
        $handler = new ListProductVariantsHandler($this->source);

        $products = $handler(new ListProductVariants([self::CAP, self::ABSENT, self::BOOTS, strtoupper(self::CAP)]));

        self::assertSame([self::CAP, self::BOOTS], array_map(static fn ($p): string => $p->productId, $products));
        self::assertSame([], $products[0]->variantAxes);
        self::assertSame([['', self::CAP_DEFAULT]], array_map(static fn ($v): array => [$v->label, $v->variantId], $products[0]->variants));
        self::assertSame(['Kolor', 'Rozmiar'], $products[1]->variantAxes);
        self::assertSame(
            [['czarne, 42', self::BOOTS_BLACK_42], ['Białe, 43', self::BOOTS_WHITE_43]],
            array_map(static fn ($v): array => [$v->label, $v->variantId], $products[1]->variants),
        );
        self::assertSame(['ofProducts'], $this->source->calls);
    }

    public function testListProductVariantsRefusesNoIdMoreThanAHundredAndANonUuid(): void
    {
        $handler = new ListProductVariantsHandler($this->source);

        foreach ([[], array_map(static fn (int $i): string => \sprintf('b0000000-0000-4000-8000-%012d', $i), range(1, 101)), ['not-a-uuid']] as $ids) {
            try {
                $handler(new ListProductVariants($ids));
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->source->calls);
    }

    public function testIdentitiesCarryTheKeyOfTheVariantAndTheTaxClassOrderedByVariantId(): void
    {
        $handler = new ListVariantIdentitiesHandler($this->source);

        $all = $handler(new ListVariantIdentities(null, 10));

        self::assertSame(
            [
                [self::BOOTS_BLACK_42, 'buty-puls/czarne-42', 'standard'],
                [self::BOOTS_WHITE_43, 'buty-puls/biale-43', 'standard'],
                [self::CAP_DEFAULT, 'czapka/standard', 'standard'],
            ],
            array_map(static fn ($i): array => [$i->variantId, $i->key, $i->taxClass], $all),
        );
    }

    public function testIdentitiesComeInBatchesAfterTheGivenVariantId(): void
    {
        $handler = new ListVariantIdentitiesHandler($this->source);

        $first = $handler(new ListVariantIdentities(null, 2));
        $second = $handler(new ListVariantIdentities($first[1]->variantId, 2));
        $third = $handler(new ListVariantIdentities(strtoupper($second[0]->variantId), 2));

        self::assertCount(2, $first);
        self::assertSame([self::CAP_DEFAULT], array_map(static fn ($i): string => $i->variantId, $second));
        self::assertSame([], $third);
    }

    public function testIdentitiesRefuseALimitOutOfRangeAndANonUuidCursor(): void
    {
        $handler = new ListVariantIdentitiesHandler($this->source);

        foreach ([new ListVariantIdentities(null, 0), new ListVariantIdentities(null, 1001), new ListVariantIdentities('not-a-uuid', 10)] as $query) {
            try {
                $handler($query);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->source->calls);
        self::assertCount(3, $handler(new ListVariantIdentities(null, 1000)));
    }

    public function testSummariesLeaveOutTheVariantsOfADraftLikeUnknownVariants(): void
    {
        $this->source = $this->withDraft();

        $summaries = $this->summaries()(new GetVariantSummaries([self::DRAFT_M, self::CAP_DEFAULT]));

        self::assertSame([self::CAP_DEFAULT], array_map(static fn ($s): string => $s->variantId, $summaries));
        self::assertSame([], $this->summaries()(new GetVariantSummaries([self::DRAFT_M])));
    }

    public function testListProductVariantsLeavesOutADraftByDefault(): void
    {
        $this->source = $this->withDraft();

        $products = new ListProductVariantsHandler($this->source)(new ListProductVariants([self::DRAFT, self::CAP]));

        self::assertSame([self::CAP], array_map(static fn ($p): string => $p->productId, $products));
    }

    public function testListProductVariantsWithDraftsListsTheDraftWithItsVariants(): void
    {
        $this->source = $this->withDraft();

        $products = new ListProductVariantsHandler($this->source)(new ListProductVariants([self::DRAFT, self::CAP], includeDrafts: true));

        self::assertSame([self::DRAFT, self::CAP], array_map(static fn ($p): string => $p->productId, $products));
        self::assertSame(['Rozmiar'], $products[0]->variantAxes);
        self::assertSame([['M', self::DRAFT_M]], array_map(static fn ($v): array => [$v->label, $v->variantId], $products[0]->variants));
    }

    public function testListProductVariantsCarriesTheTaxClassOfTheProduct(): void
    {
        $this->source = $this->withDraft();

        $products = new ListProductVariantsHandler($this->source)(new ListProductVariants([self::DRAFT, self::BOOTS], includeDrafts: true));

        self::assertSame(['reduced', 'standard'], array_map(static fn ($p): string => $p->taxClass, $products));
    }

    public function testGetVariantAndIdentitiesSeeTheVariantsOfADraft(): void
    {
        $this->source = $this->withDraft();

        $variant = new GetVariantHandler($this->source)(new GetVariant(self::DRAFT_M));
        self::assertNotNull($variant);
        self::assertSame([self::DRAFT, 'reduced'], [$variant->productId, $variant->taxClass]);
        self::assertContains(
            'kurtka-szkic/m',
            array_map(static fn ($i): string => $i->key, new ListVariantIdentitiesHandler($this->source)(new ListVariantIdentities(null, 10))),
        );
    }

    /**
     * The products of setUp() and a draft with one variant.
     */
    private function withDraft(): FakeVariantSource
    {
        return new FakeVariantSource([
            [
                'id' => self::BOOTS, 'slug' => 'buty-puls', 'name' => 'Buty Puls', 'taxClass' => 'standard', 'axes' => ['Kolor', 'Rozmiar'],
                'mainImage' => null,
                'variants' => [['id' => self::BOOTS_BLACK_42, 'values' => ['czarne', '42']]],
            ],
            [
                'id' => self::CAP, 'slug' => 'czapka', 'name' => 'Czapka', 'taxClass' => 'standard', 'axes' => [],
                'mainImage' => null,
                'variants' => [['id' => self::CAP_DEFAULT, 'values' => []]],
            ],
            [
                'id' => self::DRAFT, 'slug' => 'kurtka-szkic', 'name' => 'Kurtka', 'taxClass' => 'reduced', 'axes' => ['Rozmiar'],
                'mainImage' => null, 'status' => 'draft',
                'variants' => [['id' => self::DRAFT_M, 'values' => ['M']]],
            ],
        ]);
    }

    private function summaries(): GetVariantSummariesHandler
    {
        return new GetVariantSummariesHandler($this->source, new class implements ImageUrlGenerator {
            public function url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string
            {
                return \sprintf('https://img.test/%s/%d.%s', $imageKey, $width, $format->value);
            }
        });
    }
}
