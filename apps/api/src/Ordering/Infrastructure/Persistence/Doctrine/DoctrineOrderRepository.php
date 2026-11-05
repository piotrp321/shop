<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

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
     * Writes the confirmation marker, the only field that changes after placement. The version the order was read at
     * must still be stored: the UPDATE checks it (and raises it), so of two concurrent saves only one succeeds.
     */
    public function save(Order $order): void
    {
        $record = $this->entityManager->find(OrderRecord::class, $order->id()->toString()) ?? throw new OrderNotFound();
        if ($record->version !== $order->version()) {
            throw new OrderModifiedConcurrently();
        }
        $record->confirmationSentAt = $order->confirmationSentAt();

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new OrderModifiedConcurrently();
        }
    }

    public function get(OrderId $id): Order
    {
        $record = $this->entityManager->find(OrderRecord::class, $id->toString());

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
        $record->status = $order->status()->value;
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
        $record->placedAt = $order->placedAt();
        $record->confirmationSentAt = $order->confirmationSentAt();
        $record->version = $order->version();
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
            OrderId::fromString($record->id),
            OrderNumber::fromString($record->number),
            self::CUSTOMER === $record->ownerType ? OrderOwner::customer($record->customerId ?? '') : OrderOwner::guest(),
            Contact::of(EmailAddress::fromString($record->email), $record->fullName, $record->phone),
            ShippingAddress::inPoland($record->street, $record->postalCode, $record->city),
            $lines,
            OrderStatus::from($record->status),
            IdempotencyKey::fromString($record->idempotencyKey),
            RequestFingerprint::fromString($record->requestFingerprint),
            $record->placedAt,
            $record->confirmationSentAt,
            $record->version,
        );
    }
}
