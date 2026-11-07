<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\CancellationReason;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\IdempotencyKeyReused;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\Persistence\Doctrine\Record\OrderLineRecord;
use App\Ordering\Infrastructure\Persistence\Doctrine\Record\OrderRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Maps the Order aggregate to and from its records, in the transaction of command.bus. Every write flushes at once:
 * in PlaceOrder the order is written last, so the flushes of the nested commands of Cart and Inventory have nothing
 * of Ordering to write.
 *
 * A second order with the Idempotency-Key of another (a placement from another cart that ran at the same time) is
 * refused by the unique index; the violation becomes IdempotencyKeyReused. It closes the EntityManager, like every
 * failed flush, so the transaction ends with it.
 */
final readonly class DoctrineOrderRepository implements OrderRepository
{
    /** Values of ordering_order.owner_type. */
    public const string GUEST = 'guest';
    public const string CUSTOMER = 'customer';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Order $order): void
    {
        $record = new OrderRecord($order->id()->toString());
        $this->write($order, $record);
        foreach ($order->lines() as $position => $line) {
            $lineRecord = new OrderLineRecord(Uuid::v7()->toRfc4122(), $record, $position, $line->variantId()->toString());
            $lineRecord->productId = $line->productId()->toString();
            $lineRecord->productName = $line->productName();
            $lineRecord->variantLabel = $line->variantLabel();
            $lineRecord->quantity = $line->quantity();
            $lineRecord->unitNet = $line->unitPrice()->net()->amount();
            $lineRecord->unitVat = $line->unitPrice()->vat()->amount();
            $lineRecord->unitGross = $line->unitPrice()->gross()->amount();
            $lineRecord->vatRateBp = $line->unitPrice()->vatRateBp();
            $lineRecord->taxClass = $line->unitPrice()->taxClass();
            $lineRecord->lineNet = $line->lineNet()->amount();
            $lineRecord->lineVat = $line->lineVat()->amount();
            $lineRecord->lineGross = $line->lineGross()->amount();
            $record->lines->add($lineRecord);
        }
        $this->entityManager->persist($record);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $violation) {
            if (str_contains($violation->getMessage(), OrderRecord::IDEMPOTENCY_KEY_CONSTRAINT)) {
                throw new IdempotencyKeyReused();
            }

            throw $violation;
        }
    }

    /**
     * Writes what changes after placement: the status with the payment or the cancellation (the emails are recorded
     * outside the order, in OrderNotifications). The version the order was read at must still be stored: the UPDATE
     * checks it (and raises it), so of two concurrent saves (e.g. paying and cancelling) only one succeeds.
     */
    public function save(Order $order): void
    {
        $record = $this->entityManager->find(OrderRecord::class, $order->id()->toString()) ?? throw new OrderNotFound();
        if ($record->version !== $order->version()) {
            throw new OrderModifiedConcurrently();
        }
        self::writeChanges($order, $record);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new OrderModifiedConcurrently();
        }
    }

    /**
     * Reads the order from the database even when its record is in the identity map (a hint to refresh), so a read
     * later in a transaction sees what others committed meanwhile (e.g. StartPayment after it waited for the lock of
     * a payment).
     */
    public function get(OrderId $id): Order
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(OrderRecord::class, 'o')
            ->where('o.id = :id')
            ->setParameter('id', $id->toString())
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $record || $record instanceof OrderRecord);

        return null === $record ? throw new OrderNotFound() : self::toDomain($record);
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Order
    {
        $record = $this->entityManager->getRepository(OrderRecord::class)->findOneBy(['idempotencyKey' => $key->toString()]);

        return null === $record ? null : self::toDomain($record);
    }

    private function write(Order $order, OrderRecord $record): void
    {
        $contact = $order->contact();
        $address = $order->shippingAddress();
        $totals = $order->totals();
        $record->number = $order->number()->toString();
        $record->ownerType = $order->owner()->isGuest() ? self::GUEST : self::CUSTOMER;
        $record->customerId = $order->owner()->customerId();
        $record->email = $contact->email()->toString();
        $record->fullName = $contact->fullName();
        $record->phone = $contact->phone();
        $record->street = $address->street();
        $record->postalCode = $address->postalCode();
        $record->city = $address->city();
        $record->country = $address->country();
        $record->currency = $totals->currency()->value;
        $record->totalNet = $totals->net()->amount();
        $record->totalVat = $totals->vat()->amount();
        $record->totalGross = $totals->gross()->amount();
        $record->idempotencyKey = $order->idempotencyKey()->toString();
        $record->requestFingerprint = $order->requestFingerprint()->toString();
        $record->accessToken = $order->accessToken()?->toString();
        $record->placedAt = $order->placedAt();
        $record->paymentDeadline = $order->paymentDeadline();
        self::writeChanges($order, $record);
        $record->version = $order->version();
    }

    private static function writeChanges(Order $order, OrderRecord $record): void
    {
        $record->status = $order->status()->value;
        $record->paidAt = $order->paidAt();
        $record->paymentId = $order->paymentId()?->toString();
        $record->cancelledAt = $order->cancelledAt();
        $record->cancellationReason = $order->cancellationReason()?->value;
    }

    private static function toDomain(OrderRecord $record): Order
    {
        $currency = Currency::from($record->currency);
        $lines = [];
        foreach ($record->lines as $line) {
            $lines[] = OrderLine::of(
                VariantId::fromString($line->variantId),
                ProductId::fromString($line->productId),
                $line->productName,
                $line->variantLabel,
                $line->quantity,
                PriceCopy::of($line->unitNet, $line->unitVat, $line->unitGross, $line->vatRateBp, $line->taxClass, $currency),
            );
        }

        return Order::reconstitute(
            id: OrderId::fromString($record->id),
            number: OrderNumber::fromString($record->number),
            owner: self::CUSTOMER === $record->ownerType ? OrderOwner::customer($record->customerId ?? '') : OrderOwner::guest(),
            accessToken: null === $record->accessToken ? null : AccessToken::fromString($record->accessToken),
            contact: Contact::of(EmailAddress::fromString($record->email), $record->fullName, $record->phone),
            shippingAddress: ShippingAddress::inPoland($record->street, $record->postalCode, $record->city),
            lines: $lines,
            status: OrderStatus::from($record->status),
            idempotencyKey: IdempotencyKey::fromString($record->idempotencyKey),
            requestFingerprint: RequestFingerprint::fromString($record->requestFingerprint),
            placedAt: $record->placedAt,
            paymentDeadline: $record->paymentDeadline,
            paidAt: $record->paidAt,
            paymentId: null === $record->paymentId ? null : PaymentId::fromString($record->paymentId),
            cancelledAt: $record->cancelledAt,
            cancellationReason: null === $record->cancellationReason ? null : CancellationReason::from($record->cancellationReason),
            version: $record->version,
        );
    }
}
