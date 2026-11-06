<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

use App\PaymentSimulator\Amounts;
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
 * The API of the fake provider demo-card (HTTP Basic, amounts as "123.45", expiry in Unix seconds), outside
 * /api/v1: it is no endpoint of the shop.
 */
#[AsController]
final readonly class CardApiController
{
    private const string PREFIX = '/payment-simulator/demo-card/v1/checkout-sessions';

    public function __construct(
        private ApiAuthentication $authentication,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire(env: 'PAYMENT_SIMULATOR_PUBLIC_URL')]
        private string $publicUrl,
    ) {
    }

    #[Route(self::PREFIX, name: 'payment_simulator_card_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->authentication->isAuthorized($request, Provider::DemoCard)) {
            return $this->authentication->refuse(Provider::DemoCard);
        }

        try {
            $data = Fields::body($request);
            $amount = \is_string($data['amount'] ?? null) ? Amounts::parse($data['amount']) : null;
            if (null === $amount || $amount < 1) {
                throw new InvalidRequest('amount');
            }
            $transaction = Transaction::openCard(
                Uuid::v4()->toRfc4122(),
                Fields::reference($data, 'reference'),
                $amount,
                Fields::currency($data, 'currency'),
                Fields::url($data, 'successUrl'),
                Fields::url($data, 'cancelUrl'),
                Fields::unixTime($data, 'expiresAt'),
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );
        } catch (InvalidRequest $invalid) {
            return Fields::refuse($invalid);
        }
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $transaction->id,
            'url' => rtrim($this->publicUrl, '/').'/payment-simulator/demo-card/pay/'.$transaction->id,
        ]);
    }

    #[Route(self::PREFIX.'/{sessionId}/expire', name: 'payment_simulator_card_expire', methods: ['POST'])]
    public function expire(Request $request, string $sessionId): JsonResponse
    {
        if (!$this->authentication->isAuthorized($request, Provider::DemoCard)) {
            return $this->authentication->refuse(Provider::DemoCard);
        }

        $outcome = $this->bus->dispatch(new CancelTransaction($sessionId, Provider::DemoCard))->last(HandledStamp::class)?->getResult();
        \assert($outcome instanceof CancelOutcome);
        if (!$outcome->found) {
            return Fields::notFound();
        }

        return match ($outcome->result) {
            CancelResult::Cancelled => new JsonResponse(['status' => 'expired']),
            CancelResult::Paid => new JsonResponse(['status' => 'complete'], Response::HTTP_CONFLICT),
            CancelResult::Rejected => new JsonResponse(['status' => 'failed'], Response::HTTP_CONFLICT),
            null => throw new \LogicException('A transaction that was found has a cancellation result.'),
        };
    }
}
