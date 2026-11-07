<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A webhook event of a provider that was handled, kept forever: the deduplication of the deliveries by
 * `(provider, event_id)`. Only defines the schema: DoctrinePaymentRepository writes the rows by DBAL
 * (`INSERT … ON CONFLICT DO NOTHING`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_webhook_event')]
class WebhookEventRecord
{
    #[ORM\Column(type: 'guid')]
    public string $paymentId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $receivedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 20)]
        public string $provider,
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        public string $eventId,
    ) {
    }
}
