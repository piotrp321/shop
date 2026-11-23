<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\InvalidOrderInput;
use App\Shared\Infrastructure\Http\ClientIp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * What both order endpoints do once they know the cart and the email: read the Idempotency-Key, dispatch PlaceOrder
 * (with the chosen delivery method)
 * with the address of the client (ClientIp, as the rate limits count it, for the limit of unpaid orders) and answer
 * with the order, 201 for a new one and 200 for the order of an earlier request with the same key and
 * content. A failure of the handler passes on wrapped (ProblemDetailsSubscriber unwraps it).
 */
final readonly class OrderPlacement
{
    private const string IDEMPOTENCY_KEY = 'Idempotency-Key';

    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private ClientIp $clientIp,
    ) {
    }

    /**
     * @param string $email trimmed and checked by the domain
     */
    public function place(Request $request, CartReference $cart, string $email, PlaceCustomerOrderRequest|PlaceGuestOrderRequest $details): JsonResponse
    {
        // Validated by the payload mapping: strings, an integer and an optional string.
        \assert(\is_string($details->fullName) && \is_string($details->phone) && \is_string($details->street));
        \assert(\is_string($details->postalCode) && \is_string($details->city) && \is_int($details->expectedTotalGross));
        \assert(\is_string($details->deliveryMethodId));
        \assert(null === $details->discountCode || \is_string($details->discountCode));
        $key = self::idempotencyKey($request);

        $placed = $this->commandBus
            ->dispatch(new PlaceOrder($cart, $email, $details->fullName, $details->phone, $details->street, $details->postalCode, $details->city, $details->deliveryMethodId, $details->expectedTotalGross, self::expectedLines($details->expectedLines), $key, $details->discountCode, $this->clientIp->of($request)))
            ->last(HandledStamp::class)?->getResult();
        \assert($placed instanceof PlacedOrder);

        return new JsonResponse(self::body($placed->order), $placed->replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * The list as validated by the payload mapping (ExpectedLinesFormat): objects with a string `variantId` and an
     * integer `quantity`; other keys of an element are dropped.
     *
     * @return list<array{variantId: string, quantity: int}>
     */
    private static function expectedLines(mixed $value): array
    {
        \assert(\is_array($value) && array_is_list($value));

        return array_map(static function (mixed $line): array {
            \assert(\is_array($line) && \is_string($line['variantId'] ?? null) && \is_int($line['quantity'] ?? null));

            return ['variantId' => $line['variantId'], 'quantity' => $line['quantity']];
        }, $value);
    }

    /**
     * A missing or malformed key is a 400 with a violation of the header, like a bad query parameter.
     */
    private static function idempotencyKey(Request $request): string
    {
        $key = $request->headers->get(self::IDEMPOTENCY_KEY);
        if (null === $key) {
            throw self::badKey($key, 'Nagłówek Idempotency-Key jest wymagany.');
        }
        try {
            return IdempotencyKey::fromString($key)->toString();
        } catch (InvalidOrderInput $invalid) {
            throw self::badKey($key, $invalid->getMessage());
        }
    }

    private static function badKey(?string $key, string $message): BadRequestHttpException
    {
        $violations = new ConstraintViolationList([new ConstraintViolation($message, $message, [], $key, self::IDEMPOTENCY_KEY, $key)]);

        return new BadRequestHttpException($message, new ValidationFailedException($key, $violations));
    }

    /**
     * The OrderView schema of the specification (nelmio_api_doc.yaml), `placedAt` and `paymentDeadline` in RFC 3339
     * and UTC; `accessToken` only for a guest (null for a customer).
     *
     * @return array<string, mixed>
     */
    private static function body(OrderView $order): array
    {
        return [
            'orderId' => $order->orderId,
            'number' => $order->number,
            'status' => $order->status,
            'email' => $order->email,
            'fullName' => $order->fullName,
            'phone' => $order->phone,
            'shippingAddress' => [
                'street' => $order->shippingAddress->street,
                'postalCode' => $order->shippingAddress->postalCode,
                'city' => $order->shippingAddress->city,
                'country' => $order->shippingAddress->country,
            ],
            'lines' => array_map(OrderLineJson::line(...), $order->lines),
            'discount' => OrderLineJson::discount($order->discount),
            'delivery' => OrderLineJson::delivery($order->delivery),
            'shipment' => OrderLineJson::shipment($order->shipment),
            'totals' => [
                'net' => $order->totals->net,
                'vat' => $order->totals->vat,
                'gross' => $order->totals->gross,
                'currency' => $order->totals->currency,
            ],
            'placedAt' => self::utc($order->placedAt),
            'paymentDeadline' => self::utc($order->paymentDeadline),
            'accessToken' => $order->accessToken,
        ];
    }

    private static function utc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
