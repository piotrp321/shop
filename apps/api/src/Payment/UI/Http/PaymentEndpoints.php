<?php

declare(strict_types=1);

namespace App\Payment\UI\Http;

use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Query\GetPaymentStatus;
use App\Payment\Application\Query\PaymentStatusView;
use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\InvalidPaymentInput;
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
 * What the payment endpoints of a guest and of a customer do once they know the access: read the Idempotency-Key,
 * dispatch StartPayment and answer 201 for a new payment, 200 for a replay; dispatch GetPaymentStatus. A failure of
 * the handler passes on wrapped (ProblemDetailsSubscriber unwraps it).
 */
final readonly class PaymentEndpoints
{
    private const string IDEMPOTENCY_KEY = 'Idempotency-Key';

    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        #[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus,
    ) {
    }

    public function start(Request $request, string $orderId, PaymentAccess $access, StartPaymentRequest $body): JsonResponse
    {
        // Validated by the payload mapping: one of the codes.
        \assert(\is_string($body->provider));
        $key = self::idempotencyKey($request);

        $started = $this->commandBus->dispatch(new StartPayment($orderId, $access, $body->provider, $key))->last(HandledStamp::class)?->getResult();
        \assert($started instanceof StartedPayment);

        return new JsonResponse([
            'paymentId' => $started->payment->paymentId,
            'provider' => $started->payment->provider,
            'status' => $started->payment->status,
            'redirectUrl' => $started->payment->redirectUrl,
        ], $started->replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    public function status(string $orderId, PaymentAccess $access): JsonResponse
    {
        $status = $this->queryBus->dispatch(new GetPaymentStatus($orderId, $access))->last(HandledStamp::class)?->getResult();
        \assert($status instanceof PaymentStatusView);
        $latest = $status->latestPayment;

        return new JsonResponse([
            'orderNumber' => $status->orderNumber,
            'orderStatus' => $status->orderStatus,
            'paymentDeadline' => $status->paymentDeadline->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'totalGross' => $status->totalGross,
            'currency' => $status->currency,
            'canStartPayment' => $status->canStartPayment,
            'latestPayment' => null === $latest ? null : ['provider' => $latest->provider, 'status' => $latest->status, 'resumeUrl' => $latest->resumeUrl],
        ]);
    }

    /**
     * A missing or malformed key is a 400 with a violation of the header, like a bad query parameter.
     */
    private static function idempotencyKey(Request $request): string
    {
        $key = $request->headers->get(self::IDEMPOTENCY_KEY);
        if (null === $key) {
            throw self::badKey($key, \sprintf('The %s header is required.', self::IDEMPOTENCY_KEY));
        }
        try {
            return IdempotencyKey::fromString($key)->toString();
        } catch (InvalidPaymentInput $invalid) {
            throw self::badKey($key, $invalid->getMessage());
        }
    }

    private static function badKey(?string $key, string $message): BadRequestHttpException
    {
        $violations = new ConstraintViolationList([new ConstraintViolation($message, $message, [], $key, self::IDEMPOTENCY_KEY, $key)]);

        return new BadRequestHttpException($message, new ValidationFailedException($key, $violations));
    }
}
