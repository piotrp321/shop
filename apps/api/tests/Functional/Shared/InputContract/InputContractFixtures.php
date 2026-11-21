<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The named states the rows of the input contract start from, built with the factories and fixtures of the modules
 * on shop_test inside the transaction of the test, so every test builds its own from nothing. A fixture gives the
 * values of the placeholders of its rows.
 */
final readonly class InputContractFixtures
{
    public const string NONE = 'none';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, string> the value of each placeholder, by its name
     */
    public function build(string $fixture): array
    {
        return match ($fixture) {
            self::NONE => [],
            'product' => ['productId' => $this->product()],
            'deliveryMethod' => ['id' => OrderingFixture::freeDeliveryMethod($this->entityManager->getConnection())],
            'discountCode' => ['id' => $this->discountCode()],
            default => throw new \LogicException(\sprintf('No fixture "%s".', $fixture)),
        };
    }

    /**
     * A published Catalog product with one variant in a new category.
     */
    private function product(): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product->id()->toString();
    }

    private function discountCode(): string
    {
        $code = DiscountCodeFactory::createOne();
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code->id()->toString();
    }
}
