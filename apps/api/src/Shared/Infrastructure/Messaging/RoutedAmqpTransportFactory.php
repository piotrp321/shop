<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Replaces the AMQP transport factory: every AMQP transport is a RoutedAmqpTransport.
 *
 * @implements TransportFactoryInterface<RoutedAmqpTransport>
 */
final readonly class RoutedAmqpTransportFactory implements TransportFactoryInterface
{
    /**
     * @param TransportFactoryInterface<TransportInterface> $inner
     */
    public function __construct(private TransportFactoryInterface $inner)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        unset($options['transport_name']);

        return new RoutedAmqpTransport(Connection::fromDsn($dsn, $options), $serializer);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return $this->inner->supports($dsn, $options);
    }
}
