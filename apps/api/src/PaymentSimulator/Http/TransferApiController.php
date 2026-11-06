<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

use App\PaymentSimulator\CancelOutcome;
use App\PaymentSimulator\CancelResult;
use App\PaymentSimulator\CancelTransaction;
use App\PaymentSimulator\Provider;
use App\PaymentSimulator\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The API of the fake provider demo-transfer (Bearer key, amounts in minor units, times in RFC 3339), outside
 * /api/v1: it is no endpoint of the shop. The OpenAPI documents of the shop do not list it.
 */
#[AsController]
final readonly class TransferApiController
{
    private const string PREFIX = '/payment-simulator/demo-transfer/api/transactions';

    public function __construct(
        private ApiAuthentication $authentication,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire(env: 'PAYMENT_SIMULATOR_PUBLIC_URL')]
        private string $publicUrl,
    ) {
    }

    #[Route(self::PREFIX, name: 'payment_simulator_transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->authentication->isAuthorized($request, Provider::DemoTransfer)) {
            return $this->authentication->refuse(Provider::DemoTransfer);
        }

        try {
            $data = Fields::body($request);
            $transaction = Transaction::openTransfer(
                Uuid::v4()->toRfc4122(),
                Fields::reference($data, 'merchantRef'),
                Fields::positiveInt($data, 'amount'),
                Fields::currency($data, 'currency'),
                Fields::url($data, 'returnUrl'),
                Fields::rfc3339($data, 'expiresAt'),
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );
        } catch (InvalidRequest $invalid) {
            return Fields::refuse($invalid);
        }
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return new JsonResponse([
            'transactionId' => $transaction->id,
            'paymentUrl' => rtrim($this->publicUrl, '/').'/payment-simulator/demo-transfer/pay/'.$transaction->id,
        ], Response::HTTP_CREATED);
    }

    #[Route(self::PREFIX.'/{transactionId}/cancel', name: 'payment_simulator_transfer_cancel', methods: ['POST'])]
    public function cancel(Request $request, string $transactionId): JsonResponse
    {
        if (!$this->authentication->isAuthorized($request, Provider::DemoTransfer)) {
            return $this->authentication->refuse(Provider::DemoTransfer);
        }

        $outcome = $this->bus->dispatch(new CancelTransaction($transactionId, Provider::DemoTransfer))->last(HandledStamp::class)?->getResult();
        \assert($outcome instanceof CancelOutcome);
        if (!$outcome->found) {
            return Fields::notFound();
        }

        return match ($outcome->result) {
            CancelResult::Cancelled => new JsonResponse(['status' => 'CANCELLED']),
            CancelResult::Paid => new JsonResponse(['status' => 'PAID'], Response::HTTP_CONFLICT),
            CancelResult::Rejected => new JsonResponse(['status' => 'REJECTED'], Response::HTTP_CONFLICT),
            null => throw new \LogicException('A transaction that was found has a cancellation result.'),
        };
    }
}
