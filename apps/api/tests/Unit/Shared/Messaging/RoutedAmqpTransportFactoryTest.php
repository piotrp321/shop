<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messaging;

use App\Shared\Infrastructure\Messaging\RoutedAmqpTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Without publisher confirms the sender never waits for the broker, so a returned message would go unnoticed: the
 * factory refuses such a transport instead of creating one that only looks safe. Creating a transport does not connect.
 */
final class RoutedAmqpTransportFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('confirmedTransports')]
    public function testCreatesATransportWithPublisherConfirms(string $dsn, array $options): void
    {
        $this->expectNotToPerformAssertions();

        $this->factory()->createTransport($dsn, $options, new PhpSerializer());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function confirmedTransports(): iterable
    {
        yield 'option' => ['amqp://guest:guest@localhost:5672/%2f/events', ['confirm_timeout' => 5, 'transport_name' => 'events']];
        yield 'query' => ['amqp://guest:guest@localhost:5672/%2f/events?confirm_timeout=5', []];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('unconfirmedTransports')]
    public function testRefusesATransportWithoutPublisherConfirms(string $dsn, array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->createTransport($dsn, $options, new PhpSerializer());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function unconfirmedTransports(): iterable
    {
        yield 'none' => ['amqp://guest:guest@localhost:5672/%2f/events', []];
        yield 'empty option' => ['amqp://guest:guest@localhost:5672/%2f/events', ['confirm_timeout' => '']];
        yield 'empty query' => ['amqp://guest:guest@localhost:5672/%2f/events?confirm_timeout=', []];
    }

    private function factory(): RoutedAmqpTransportFactory
    {
        return new RoutedAmqpTransportFactory(new AmqpTransportFactory());
    }
}
