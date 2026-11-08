<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Mail;

use App\Shared\Infrastructure\Mail\SmtpTimeoutTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * The `timeout` option of an SMTP DSN (seconds, also a fraction) bounds every read and write of the SMTP socket;
 * without it PHP's default_socket_timeout applies. Creating the transport opens no connection.
 */
final class SmtpTimeoutTransportFactoryTest extends TestCase
{
    public function testTheTimeoutOfTheDsnIsTheTimeoutOfTheSocket(): void
    {
        self::assertSame(10.0, self::streamOf('smtp://mailpit:1025?timeout=10')->getTimeout());
        self::assertSame(2.5, self::streamOf('smtps://user:secret@smtp.example.com:465?timeout=2.5')->getTimeout());
    }

    public function testWithoutTheOptionTheDefaultSocketTimeoutApplies(): void
    {
        self::assertSame((float) \ini_get('default_socket_timeout'), self::streamOf('smtp://mailpit:1025')->getTimeout());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['ten'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidTimeouts')]
    public function testATimeoutThatIsNoPositiveNumberIsRefused(string $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SmtpTimeoutTransportFactory(new EsmtpTransportFactory())->create(Dsn::fromString('smtp://mailpit:1025?timeout='.$timeout));
    }

    public function testItSupportsWhatTheSmtpFactorySupports(): void
    {
        $factory = new SmtpTimeoutTransportFactory(new EsmtpTransportFactory());

        self::assertTrue($factory->supports(Dsn::fromString('smtp://mailpit:1025?timeout=10')));
        self::assertFalse($factory->supports(Dsn::fromString('null://null')));
        self::assertFalse(new SmtpTimeoutTransportFactory(new NullTransportFactory())->supports(Dsn::fromString('smtp://mailpit:1025')));
    }

    private static function streamOf(string $dsn): SocketStream
    {
        $transport = new SmtpTimeoutTransportFactory(new EsmtpTransportFactory())->create(Dsn::fromString($dsn));
        self::assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }
}
