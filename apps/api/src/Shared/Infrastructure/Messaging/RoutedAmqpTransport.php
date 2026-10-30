<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * An AMQP transport whose send fails unless the broker routed the message to a queue. A publisher confirm alone does
 * not tell: RabbitMQ acks a message it had nowhere to route (the queue or its binding is gone). Published as
 * mandatory, such a message comes back as basic.return before the ack, while the sender waits for the confirm.
 */
final class RoutedAmqpTransport extends AmqpTransport
{
    private bool $returned = false;

    public function __construct(private readonly Connection $amqpConnection, ?SerializerInterface $serializer = null)
    {
        parent::__construct($amqpConnection, $serializer);
    }

    public function send(Envelope $envelope): Envelope
    {
        $channel = $this->connectedChannel();
        $this->returned = false;
        // true: keep waiting for the confirm that follows the return.
        $channel->setReturnCallback(function (): bool {
            $this->returned = true;

            return true;
        });

        $stamp = $envelope->last(AmqpStamp::class);
        $envelope = parent::send($envelope->with(new AmqpStamp(
            $stamp?->getRoutingKey(),
            ($stamp?->getFlags() ?? \AMQP_NOPARAM) | \AMQP_MANDATORY,
            $stamp?->getAttributes() ?? [],
        )));

        if ($this->returned) {
            throw new TransportException('The broker could not route the message to any queue.');
        }
        // The connection was lost during the publish and the message went out on a new channel without the callback.
        if ($this->amqpConnection->channel() !== $channel) {
            throw new TransportException('The routing of the message cannot be confirmed after a reconnect.');
        }

        return $envelope;
    }

    /**
     * The channel the publish will use: a disconnected one is replaced now, as the publish itself would do.
     */
    private function connectedChannel(): \AMQPChannel
    {
        try {
            $channel = $this->amqpConnection->channel();
            if (!$channel->isConnected()) {
                $this->amqpConnection->clear();
                $channel = $this->amqpConnection->channel();
            }
        } catch (\AMQPException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return $channel;
    }
}
