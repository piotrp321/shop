<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Mail;

use App\Ordering\Application\Port\OrderNotifications;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * A sending of an email ends well within the lease of its claim (OrderNotifications::LEASE), so a claim never expires
 * while its holder is still talking to the SMTP server. The timeout bounds each read and write of the socket; sending
 * one email takes about seven exchanges (connect, greeting, EHLO, MAIL FROM, RCPT TO, DATA, the message), so ten
 * timeouts bound a sending. Checked on the mailer of the container, configured by MAILER_DSN of the environment
 * (infra/docker-compose.yml for the stack and CI), and on the default of phpunit.dist.xml.
 */
final class SmtpTimeoutWithinTheLeaseTest extends KernelTestCase
{
    private const int EXCHANGES_BOUND = 10;

    public function testTheSmtpTimeoutOfTheMailerBoundsASendingWithinTheLease(): void
    {
        $factory = self::getContainer()->get('mailer.transport_factory');
        self::assertInstanceOf(Transport::class, $factory);
        $dsn = $_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN');
        self::assertIsString($dsn);

        // The transport the mailer of the container builds from MAILER_DSN.
        $transport = $factory->fromString($dsn);
        self::assertInstanceOf(EsmtpTransport::class, $transport, 'MAILER_DSN is an SMTP DSN');
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);

        self::assertLessThan(self::leaseSeconds(), $stream->getTimeout() * self::EXCHANGES_BOUND);
    }

    public function testTheDefaultDsnOfTheTestsHasTheTimeout(): void
    {
        $phpunit = simplexml_load_file(\dirname(__DIR__, 4).'/phpunit.dist.xml');
        self::assertNotFalse($phpunit);
        $dsn = $phpunit->xpath('//php/env[@name="MAILER_DSN"]/@value');
        self::assertIsArray($dsn);
        self::assertCount(1, $dsn);

        $timeout = Dsn::fromString((string) $dsn[0])->getOption('timeout');

        self::assertIsNumeric($timeout);
        self::assertLessThan(self::leaseSeconds(), (float) $timeout * self::EXCHANGES_BOUND);
    }

    private static function leaseSeconds(): int
    {
        return new \DateTimeImmutable('@0')->modify(OrderNotifications::LEASE)->getTimestamp();
    }
}
