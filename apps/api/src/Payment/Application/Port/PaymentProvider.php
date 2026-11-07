<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Application\InvalidWebhook;
use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\ProviderEvent;
use App\Payment\Application\ProviderSession;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\Amount;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One payment provider behind the API it offers (the adapters translate its amounts, times and statuses). Every
 * call is bounded by 5 s. Its webhooks are verified and read by the same adapter.
 */
#[AutoconfigureTag(self::TAG)]
interface PaymentProvider
{
    public const string TAG = 'app.payment_provider';

    public function code(): ProviderCode;

    /**
     * Opens the page of the provider where the customer pays the amount, until `expiresAt`; the provider sends the
     * customer back to `returnUrl`.
     *
     * @throws PaymentProviderUnavailable
     */
    public function createSession(PaymentId $paymentId, Amount $amount, string $orderNumber, string $returnUrl, \DateTimeImmutable $expiresAt): ProviderSession;

    /**
     * Cancels a session so it can no longer be paid; a cancelled session is cancelled again without error.
     *
     * @throws PaymentProviderUnavailable
     */
    public function cancelSession(ProviderPaymentId $providerPaymentId): SessionCancellation;

    /**
     * Verifies the signature of a webhook of the provider (hash_equals, before the body is read) and reads the event.
     *
     * @param array<string, string> $headers the headers of the request by lower-case name (the first value of each)
     * @param string                $rawBody the body exactly as it came, which the signature covers
     *
     * @return ProviderEvent|null null for a signed event of a type the shop does not handle (ignored)
     *
     * @throws InvalidWebhook a missing or wrong signature, a timestamp outside the tolerance, a malformed body or a
     *                        succeeded payment without the moment of its execution
     */
    public function parseWebhook(array $headers, string $rawBody, \DateTimeImmutable $now): ?ProviderEvent;
}
