<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRedirectRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrinePromotionRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

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
            'newDeliveryMethod' => ['id' => $this->deliveryMethod()],
            'discountCode' => ['id' => $this->discountCode()],
            'promotion' => ['id' => $this->promotion()],
            'guestCheckout' => $this->checkout(null),
            'customerCheckout' => $this->checkout(TestTokenFactory::CUSTOMER_ID),
            'guestOrder' => $this->guestOrder(),
            'customerOrder' => ['orderId' => OrderingFixture::order($this->entityManager->getConnection(), customerId: TestTokenFactory::CUSTOMER_ID)],
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

    /**
     * A new active delivery method at version 1, named „Kurier do zmiany”.
     */
    private function deliveryMethod(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO delivery_method (id, name, description, price_gross, free_from_gross, active, position, tracking_url_template, created_at, updated_at, version)
             VALUES (?, 'Kurier do zmiany', '', 1500, NULL, true, 20, NULL, now(), now(), 1)",
            [$id],
        );

        return $id;
    }

    private function discountCode(): string
    {
        $code = DiscountCodeFactory::createOne();
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code->id()->toString();
    }

    /**
     * A planned promotion of a variant with a price, at version 1.
     */
    private function promotion(): string
    {
        [$variantId] = OrderingFixture::variant($this->entityManager->getConnection(), 1000, 5);
        $id = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable();
        new DoctrinePromotionRepository($this->entityManager)->save(Promotion::plan(
            PromotionId::fromString($id), 'Jesień', PercentOff::of(20), $now->modify('+1 day'), $now->modify('+5 days'),
            [VariantId::fromString($variantId)], null, $now,
        ));
        $this->entityManager->clear();

        return $id;
    }

    /**
     * An open cart of a guest (null) or of the customer of TestTokenFactory with one unit of a variant of 12,30 zł,
     * and the free delivery method: what an order is placed from, for 12,30 zł.
     *
     * @return array{cartId: string, deliveryMethodId: string, variantId: string}
     */
    private function checkout(?string $customerId): array
    {
        $connection = $this->entityManager->getConnection();
        [$variantId] = OrderingFixture::variant($connection, 1000, 5);

        return [
            'cartId' => CartFixture::cart($connection, customerId: $customerId, lines: [[$variantId, 1]]),
            'deliveryMethodId' => OrderingFixture::freeDeliveryMethod($connection),
            'variantId' => $variantId,
        ];
    }

    /**
     * A new order of a guest and the access token it is reached with.
     *
     * @return array{orderId: string, accessToken: string}
     */
    private function guestOrder(): array
    {
        $connection = $this->entityManager->getConnection();
        $orderId = OrderingFixture::order($connection);
        $accessToken = $connection->fetchOne('SELECT access_token FROM ordering_order WHERE id = ?', [$orderId]);
        \assert(\is_string($accessToken));

        return ['orderId' => $orderId, 'accessToken' => $accessToken];
    }
}
