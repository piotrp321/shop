<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Adds the `timeout` option (seconds, > 0, also a fraction) to the SMTP DSNs of Symfony Mailer, which has none: it is
 * the timeout of every connect, read and write of the SMTP socket (SocketStream), instead of PHP's
 * default_socket_timeout (60 s). It keeps a sending of an email short, within the lease of its claim in Ordering
 * (OrderNotifications::LEASE). Everything else is up to the SMTP factory of the framework.
 */
#[AsDecorator('mailer.transport_factory.smtp')]
final readonly class SmtpTimeoutTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private TransportFactoryInterface $inner,
    ) {
    }

    /**
     * @throws InvalidArgumentException the timeout is no positive number
     */
    public function create(Dsn $dsn): TransportInterface
    {
        $timeout = $dsn->getOption('timeout');
        if (null !== $timeout && (!is_numeric($timeout) || (float) $timeout <= 0)) {
            throw new InvalidArgumentException('The "timeout" option of an SMTP DSN is a positive number of seconds.');
        }

        $transport = $this->inner->create($dsn);
        if (null !== $timeout && $transport instanceof SmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout((float) $timeout);
            }
        }

        return $transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return $this->inner->supports($dsn);
    }
}
