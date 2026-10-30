<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Replaces the AMQP transport factory: every AMQP transport is a RoutedAmqpTransport. It builds the transport as
 * Symfony's factory does (the Connection must be passed in, since AmqpTransport keeps it private), and refuses one
 * without publisher confirms: only the wait for the confirm processes a returned message.
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
        // The DSN query overrides the options, as in Connection::fromDsn().
        parse_str(parse_url($dsn, \PHP_URL_QUERY) ?: '', $query);
        $confirmTimeout = $query['confirm_timeout'] ?? $options['confirm_timeout'] ?? null;
        if (!\is_scalar($confirmTimeout) || '' === (string) $confirmTimeout) {
            throw new InvalidArgumentException('An AMQP transport needs the "confirm_timeout" option to detect a message the broker could not route.');
        }

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
