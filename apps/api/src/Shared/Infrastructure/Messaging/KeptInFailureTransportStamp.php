<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Put by {@see KeepFailedMessageListener} on the envelope of the failed event: the message was kept in the failure
 * transport as a new message with this id. Never sent (it marks one attempt, not the message).
 */
final readonly class KeptInFailureTransportStamp implements NonSendableStampInterface
{
    public function __construct(public string $id)
    {
    }
}
