<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\Port\PaymentProvider;
use App\Payment\Application\ProviderSession;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\Amount;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;

/**
 * A provider that opens numbered sessions and answers a cancellation as told, recording every call.
 */
final class FakePaymentProvider implements PaymentProvider
{
    /** @var list<array{PaymentId, Amount, string, string, \DateTimeImmutable}> */
    public array $created = [];
    /** @var list<string> */
    public array $cancelled = [];
    public SessionCancellation $cancellation = SessionCancellation::Cancelled;
    public bool $createFails = false;
    public bool $cancelFails = false;

    public function __construct(private readonly ProviderCode $code)
    {
    }

    public function code(): ProviderCode
    {
        return $this->code;
    }

    public function createSession(PaymentId $paymentId, Amount $amount, string $orderNumber, string $returnUrl, \DateTimeImmutable $expiresAt): ProviderSession
    {
        if ($this->createFails) {
            throw new PaymentProviderUnavailable('The provider did not answer.');
        }
        $this->created[] = [$paymentId, $amount, $orderNumber, $returnUrl, $expiresAt];
        $id = \sprintf('%s_%d', str_replace('-', '_', $this->code->value), \count($this->created));

        return new ProviderSession(ProviderPaymentId::fromString($id), 'http://provider.test/pay/'.$id);
    }

    public function cancelSession(ProviderPaymentId $providerPaymentId): SessionCancellation
    {
        if ($this->cancelFails) {
            throw new PaymentProviderUnavailable('The provider did not answer.');
        }
        $this->cancelled[] = $providerPaymentId->toString();

        return $this->cancellation;
    }
}
