<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\DecisionOutcome;
use App\PaymentSimulator\Provider;
use App\PaymentSimulator\ProviderCredentials;
use App\PaymentSimulator\Transaction;
use App\PaymentSimulator\Transactions;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The payment page the customer is sent to (the URL a provider returns from the session API): the choice to pay or
 * reject, then back to the shop. The form carries a CSRF token, the HMAC-SHA256 of the transaction id with the webhook
 * secret of the provider, so only a page the simulator rendered can decide a transaction.
 */
#[AsController]
final readonly class PaymentPageController
{
    private const string PATH = '/payment-simulator/{provider}/pay/{transactionId}';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ProviderCredentials $credentials,
        private ClockInterface $clock,
    ) {
    }

    #[Route(self::PATH, name: 'payment_simulator_page', methods: ['GET'])]
    public function show(string $provider, string $transactionId): Response
    {
        $transaction = $this->find(Provider::tryFrom($provider), $transactionId);
        if (null === $transaction) {
            return PaymentPageHtml::notFound();
        }
        if ($transaction->isClosed(\DateTimeImmutable::createFromInterface($this->clock->now()))) {
            return PaymentPageHtml::closed($transaction);
        }

        return PaymentPageHtml::open(
            $transaction,
            '/payment-simulator/'.$transaction->provider->value.'/pay/'.$transaction->id,
            $this->csrfToken($transaction->provider, $transaction->id),
        );
    }

    #[Route(self::PATH, name: 'payment_simulator_decision', methods: ['POST'])]
    public function decide(Request $request, string $provider, string $transactionId): Response
    {
        $kind = Provider::tryFrom($provider);
        if (null === $kind) {
            return PaymentPageHtml::notFound();
        }
        $token = $request->request->get('_token');
        if (!\is_string($token) || !hash_equals($this->csrfToken($kind, $transactionId), $token)) {
            return PaymentPageHtml::badToken();
        }
        $decision = $request->request->get('decision');
        if (!\in_array($decision, ['pay', 'reject'], true)) {
            return PaymentPageHtml::badDecision();
        }

        $outcome = $this->bus->dispatch(new DecideTransaction($transactionId, $kind, 'pay' === $decision))->last(HandledStamp::class)?->getResult();
        \assert($outcome instanceof DecisionOutcome);

        return $outcome->found ? new RedirectResponse($outcome->returnUrl, Response::HTTP_SEE_OTHER) : PaymentPageHtml::notFound();
    }

    private function find(?Provider $provider, string $transactionId): ?Transaction
    {
        if (null === $provider || !Transactions::isId($transactionId)) {
            return null;
        }
        $transaction = $this->entityManager->find(Transaction::class, $transactionId);

        return $transaction instanceof Transaction && $transaction->provider === $provider ? $transaction : null;
    }

    private function csrfToken(Provider $provider, string $transactionId): string
    {
        return hash_hmac('sha256', $transactionId, $this->credentials->webhookSecret($provider));
    }
}
