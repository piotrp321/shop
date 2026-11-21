<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRedirectRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Cart\CartFixture;
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
            'product' => $this->product(),
            'variant' => $this->variant(),
            'category' => ['id' => $this->category()->id()->toString()],
            'categoryRedirect' => ['path' => $this->categoryRedirect()],
            'guestCart' => ['cartId' => CartFixture::cart($this->entityManager->getConnection())],
            'deliveryMethod' => ['id' => OrderingFixture::freeDeliveryMethod($this->entityManager->getConnection())],
            'discountCode' => ['id' => $this->discountCode()],
            default => throw new \LogicException(\sprintf('No fixture "%s".', $fixture)),
        };
    }

    /**
     * A published Catalog product with one variant in a new category.
     *
     * @return array{productId: string, categoryId: string}
     */
    private function product(): array
    {
        $category = $this->category();
        $product = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return ['productId' => $product->id()->toString(), 'categoryId' => $category->id()->toString()];
    }

    /**
     * The default variant of a new product with a current price and a stock.
     *
     * @return array{variantId: string, productId: string}
     */
    private function variant(): array
    {
        [$variantId, $productId] = OrderingFixture::variant($this->entityManager->getConnection(), 1000, 5);

        return ['variantId' => $variantId, 'productId' => $productId];
    }

    /**
     * A new empty root category, at version 1.
     */
    private function category(): Category
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);

        return $category;
    }

    /**
     * An old address that leads to a new category.
     */
    private function categoryRedirect(): string
    {
        $category = $this->category();
        $path = 'stary-'.$category->slug()->value();
        new DoctrineCategoryRedirectRepository($this->entityManager)->record([$path => $category->id()]);

        return $path;
    }

    private function discountCode(): string
    {
        $code = DiscountCodeFactory::createOne();
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code->id()->toString();
    }
}
