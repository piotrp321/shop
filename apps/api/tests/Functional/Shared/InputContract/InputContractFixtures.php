<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ReindexOutcome;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRedirectRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\StaffTransition;
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
use App\Tests\Support\OriginalImages;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The named states the rows of the input contract start from, built with the factories and fixtures of the modules
 * on shop_test inside the transaction of the test, so every test builds its own from nothing. A fixture gives the
 * values of the placeholders of its rows.
 */
final readonly class InputContractFixtures
{
    public const string NONE = 'none';

    /** The original of the image the product writes of the rows name: the fixtures put it in the test bucket. */
    private const string IMAGE_KEY = 'originals/0c0e7a0c0e7a0c0e7a0c0e7a0c0e7a0c0e7a0c0e7a0c0e7a0c0e7a0c0e7a0c0e.jpg';
    /** The one product the fixture `indexedProduct` writes to the index of the tests, so the index keeps one copy. */
    private const string INDEXED_PRODUCT = '0193a0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    /**
     * @param ContainerInterface|null $container the container of the test, for the fixtures that write to the image
     *                                           storage or to the search index
     */
    public function __construct(private EntityManagerInterface $entityManager, private ?ContainerInterface $container = null)
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
            'productWrite' => $this->productWrite(),
            'productEdit' => $this->productEdit(),
            'indexedProduct' => $this->indexedProduct(),
            'variant' => $this->variant(),
            'category' => ['id' => $this->category()->id()->toString()],
            'categoryRedirect' => ['path' => $this->categoryRedirect()],
            'guestCart' => ['cartId' => CartFixture::cart($this->entityManager->getConnection())],
            'deliveryMethod' => ['id' => OrderingFixture::freeDeliveryMethod($this->entityManager->getConnection())],
            'newDeliveryMethod' => ['id' => $this->deliveryMethod()],
            'discountCode' => $this->discountCode(),
            'promotion' => $this->promotion(),
            'guestCheckout' => $this->checkout(null),
            'customerCheckout' => $this->checkout(TestTokenFactory::CUSTOMER_ID),
            'guestOrder' => $this->guestOrder(),
            'customerOrder' => ['orderId' => OrderingFixture::order($this->entityManager->getConnection(), customerId: TestTokenFactory::CUSTOMER_ID)],
            'paidOrder' => ['orderId' => $this->paidOrder()],
            'cancelledOrder' => ['orderId' => $this->cancelledOrder()],
            'shippedOrder' => ['orderId' => $this->shippedOrder()],
            'searchedOrder' => $this->searchedOrder(),
            'searchedCustomer' => $this->searchedCustomer(),
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
     * What a product is created from: a new category and an original in the image storage.
     *
     * @return array{categoryId: string, imageKey: string}
     */
    private function productWrite(): array
    {
        OriginalImages::exist($this->container(), self::IMAGE_KEY);

        return ['categoryId' => $this->category()->id()->toString(), 'imageKey' => self::IMAGE_KEY];
    }

    /**
     * A draft product (never published, so the values of its variants may change) at version 1 named „Buty biegowe”,
     * slug `buty-biegowe`, along the axis Rozmiar with the variants M and L, and the original of productWrite() it is
     * changed to.
     *
     * @return array{id: string, categoryId: string, imageKey: string, variantM: string, variantL: string}
     */
    private function productEdit(): array
    {
        OriginalImages::exist($this->container(), self::IMAGE_KEY);
        $category = $this->category();
        $variantM = Uuid::v7()->toRfc4122();
        $variantL = Uuid::v7()->toRfc4122();
        $product = ProductFactory::createOne([
            'name' => 'Buty biegowe',
            'slug' => 'buty-biegowe',
            'mainCategory' => $category,
            'variantAxes' => ['Rozmiar'],
            'variants' => [['id' => $variantM, 'values' => ['M']], ['id' => $variantL, 'values' => ['L']]],
            'status' => ProductStatus::Draft,
        ]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return [
            'id' => $product->id()->toString(),
            'categoryId' => $category->id()->toString(),
            'imageKey' => self::IMAGE_KEY,
            'variantM' => $variantM,
            'variantL' => $variantL,
        ];
    }

    /**
     * A published product named „Namiot Zefir” the lists of products find, also the list of the store: it is written
     * to the index of the tests by the indexing of the application. Its id is always the same, so every build replaces
     * the one document instead of leaving another one in the index after the rollback of the database.
     *
     * @return array{productId: string, productName: string}
     */
    private function indexedProduct(): array
    {
        $name = 'Namiot Zefir';
        $product = ProductFactory::createOne(['id' => self::INDEXED_PRODUCT, 'name' => $name, 'slug' => 'namiot-zefir', 'mainCategory' => $this->category()]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        $container = $this->container();
        $indexing = $container->get(ProductIndexing::class);
        \assert($indexing instanceof ProductIndexing);
        if (ReindexOutcome::Indexed !== $indexing->reindex(self::INDEXED_PRODUCT)) {
            throw new \LogicException('The product of the fixture indexedProduct is not in the index.');
        }
        $clients = $container->get('test.'.ElasticsearchClientFactory::class);
        \assert($clients instanceof ElasticsearchClientFactory);
        $alias = $container->getParameter('app.search.alias');
        \assert(\is_string($alias));
        $clients->create(5.0)->indices()->refresh(['index' => $alias]);

        return ['productId' => self::INDEXED_PRODUCT, 'productName' => $name];
    }

    private function container(): ContainerInterface
    {
        return $this->container ?? throw new \LogicException('This fixture needs the container of the test.');
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

    /**
     * An enabled discount code with a text of its own: the beginning of it is what the list of codes finds it by.
     *
     * @return array{id: string, code: string}
     */
    private function discountCode(): array
    {
        $code = DiscountCodeFactory::createOne();
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return ['id' => $code->id()->toString(), 'code' => $code->code()->toString()];
    }

    /**
     * A planned promotion of a variant with a price, at version 1, named „Jesień”.
     *
     * @return array{id: string, variantId: string}
     */
    private function promotion(): array
    {
        [$variantId] = OrderingFixture::variant($this->entityManager->getConnection(), 1000, 5);
        $id = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable();
        new DoctrinePromotionRepository($this->entityManager)->save(Promotion::plan(
            PromotionId::fromString($id), 'Jesień', PercentOff::of(20), $now->modify('+1 day'), $now->modify('+5 days'),
            [VariantId::fromString($variantId)], null, $now,
        ));
        $this->entityManager->clear();

        return ['id' => $id, 'variantId' => $variantId];
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
     * An order of a guest placed through the command of the checkout (one unit of 12,30 zł) and paid: version 2, with its
     * reservation and history, what a staff member changes.
     */
    private function paidOrder(): string
    {
        ['cartId' => $cartId, 'deliveryMethodId' => $deliveryMethodId, 'variantId' => $variantId] = $this->checkout(null);
        $placed = $this->dispatch(new PlaceOrder(CartReference::guest($cartId), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa',
            $deliveryMethodId, 1230, [['variantId' => $variantId, 'quantity' => 1]], 'fixture-'.$cartId));
        \assert($placed instanceof PlacedOrder);
        $this->dispatch(new MarkOrderPaid($placed->order->orderId, Uuid::v7()->toRfc4122(), 1230, 'PLN', new \DateTimeImmutable()));
        $this->entityManager->clear();

        return $placed->order->orderId;
    }

    /**
     * A paid order cancelled by a staff member, with its refund pending (version 3).
     */
    private function cancelledOrder(): string
    {
        $orderId = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Cancel, 2, 'Brak towaru', 'kc-staff-1', 'anna'));
        $this->entityManager->clear();

        return $orderId;
    }

    /**
     * A paid order shipped by a staff member (version 3), the state a return is registered from.
     */
    private function shippedOrder(): string
    {
        $orderId = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'anna'));
        $this->entityManager->clear();

        return $orderId;
    }

    /**
     * An order of a guest whose full name no other order of the tests has, what the search of the order list finds.
     *
     * @return array{orderId: string, orderName: string}
     */
    private function searchedOrder(): array
    {
        $name = 'Zenobia Wierzbięta';

        return ['orderId' => OrderingFixture::order($this->entityManager->getConnection(), email: 'zenobia@example.com', fullName: $name), 'orderName' => $name];
    }

    /**
     * A customer with an order and its row of the customer list, under a name no other customer of the tests has.
     *
     * @return array{customerId: string, customerName: string}
     */
    private function searchedCustomer(): array
    {
        $connection = $this->entityManager->getConnection();
        $customerId = 'kc-text-'.Uuid::v7()->toRfc4122();
        $name = 'Bogumiła Szczęsna';
        $orderId = OrderingFixture::order($connection, email: 'bogumila@example.com', customerId: $customerId, fullName: $name);
        $connection->executeStatement(
            "INSERT INTO ordering_customer (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
             VALUES (?, 'bogumila@example.com', ?, '+48600100200', 1, 0, 0, now(), now(), ?)",
            [$customerId, $name, $orderId],
        );

        return ['customerId' => $customerId, 'customerName' => $name];
    }

    private function dispatch(object $command): mixed
    {
        $bus = $this->container()->get('command.bus');
        \assert($bus instanceof MessageBusInterface);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
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
