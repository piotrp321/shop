<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\GetProductExists;
use App\Catalog\Application\Query\ListProductIdentities;
use App\Catalog\Application\Query\ProductIdentity;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Catalog queries Inventory asks through the query bus, on shop_test (rolled back after each test).
 */
final class ProductsForInventoryQueriesTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
    }

    public function testAStoredProductExistsWhateverTheLetterCaseOfItsId(): void
    {
        $id = $this->saveProduct();

        self::assertTrue($this->ask(new GetProductExists($id)));
        self::assertTrue($this->ask(new GetProductExists(strtoupper($id))));
    }

    public function testAnUnknownProductDoesNotExist(): void
    {
        $this->saveProduct();

        self::assertFalse($this->ask(new GetProductExists('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    public function testAMalformedIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ask(new GetProductExists("' OR 1=1 --"));
    }

    public function testListsTheIdentitiesOfAllProductsInBatchesById(): void
    {
        $slugs = [];
        for ($i = 1; $i <= 3; ++$i) {
            $slugs[$this->saveProduct('inventory-identity-'.$i)] = 'inventory-identity-'.$i;
        }
        ksort($slugs);
        $ids = array_keys($slugs);

        $all = $this->identities(new ListProductIdentities(null, 1000));
        self::assertSame($slugs, $this->only($all, $ids));
        self::assertSame($ids, array_keys($this->only($all, $ids)), 'ordered by id');

        $afterFirst = $this->identities(new ListProductIdentities($ids[0], 1000));
        self::assertSame([$ids[1] => $slugs[$ids[1]], $ids[2] => $slugs[$ids[2]]], $this->only($afterFirst, $ids));
        self::assertNotContains($ids[0], array_map(static fn (ProductIdentity $identity): string => $identity->id, $afterFirst));

        self::assertCount(1, $this->identities(new ListProductIdentities(null, 1)));
        self::assertSame([], $this->identities(new ListProductIdentities('ffffffff-ffff-7fff-bfff-ffffffffffff', 1000)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBatches')]
    public function testAnInvalidBatchIsRejected(?string $after, int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ask(new ListProductIdentities($after, $limit));
    }

    /** @return iterable<string, array{string|null, int}> */
    public static function invalidBatches(): iterable
    {
        yield 'limit zero' => [null, 0];
        yield 'limit above the maximum' => [null, 1001];
        yield 'cursor that is not a UUID' => ["' OR 1=1 --", 10];
    }

    /**
     * @return list<ProductIdentity>
     */
    private function identities(ListProductIdentities $query): array
    {
        $identities = $this->ask($query);
        self::assertIsArray($identities);
        self::assertContainsOnlyInstancesOf(ProductIdentity::class, $identities);

        return array_values($identities);
    }

    /**
     * @param list<ProductIdentity> $identities
     * @param list<string>          $ids
     *
     * @return array<string, string> id => slug of the identities with one of the ids, in the order they came
     */
    private function only(array $identities, array $ids): array
    {
        $found = [];
        foreach ($identities as $identity) {
            if (\in_array($identity->id, $ids, true)) {
                $found[$identity->id] = $identity->slug;
            }
        }

        return $found;
    }

    private function ask(object $query): mixed
    {
        try {
            $stamp = $this->queryBus->dispatch($query)->last(HandledStamp::class);
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw.
            $wrapped = array_values($failure->getWrappedExceptions());

            throw 1 === \count($wrapped) ? $wrapped[0] : $failure;
        }
        self::assertNotNull($stamp);

        return $stamp->getResult();
    }

    private function saveProduct(?string $slug = null): string
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(null === $slug ? ['mainCategory' => $category] : ['mainCategory' => $category, 'slug' => $slug]);
        new DoctrineProductRepository($entityManager)->save($product);

        return $product->id()->toString();
    }
}
