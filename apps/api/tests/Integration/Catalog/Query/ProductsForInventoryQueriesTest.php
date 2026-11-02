<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Query;

use App\Catalog\Application\Query\GetProductExists;
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

    private function saveProduct(): string
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($entityManager)->save($product);

        return $product->id()->toString();
    }
}
