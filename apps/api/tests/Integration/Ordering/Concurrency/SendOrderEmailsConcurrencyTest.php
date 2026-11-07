<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Kernel;
use App\Ordering\Application\OrderCancelledText;
use App\Ordering\Application\PaymentReceivedText;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Infrastructure\Mail\SymfonyOrderStatusMailer;
use App\Tests\Integration\Ordering\Mail\MailpitInbox;
use App\Tests\Integration\Ordering\Mail\SlowOrderStatusMailer;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Two deliveries of the same OrderPaid handled at once by SendPaymentReceived, each in a process with a kernel and
 * a connection of its own (two workers), against the SMTP of Mailpit. The mailer of each holds on for half a second
 * after sending, so the other delivery always comes while the sending is claimed. The orders commit, so they are
 * deleted after the test.
 */
final class SendOrderEmailsConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 3;

    private Connection $control;
    /** @var list<string> */
    private array $orders = [];
    /** @var list<MailpitInbox> */
    private array $inboxes = [];

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
    }

    protected function tearDown(): void
    {
        OrderingFixture::deleteOrders($this->control, $this->orders);
        $this->control->close();
        foreach ($this->inboxes as $inbox) {
            $inbox->clear();
        }
        parent::tearDown();
    }

    public function testTwoParallelDeliveriesOfTheSameEventSendOneEmail(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $inbox = new MailpitInbox(\sprintf('parallel-%s@example.test', bin2hex(random_bytes(6))));
            $this->inboxes[] = $inbox;
            $orderId = OrderingFixture::order($this->control, $inbox->recipient, paid: true);
            $this->orders[] = $orderId;
            $event = new OrderPaid($orderId, new \DateTimeImmutable());

            $outcomes = ParallelProcesses::run($this->control, [self::delivery($event), self::delivery($event)]);

            sort($outcomes);
            self::assertSame(['handled', 'retried: '.RecoverableMessageHandlingException::class], $outcomes, 'round '.$round);
            // Wait for anything a second sending would have delivered.
            usleep(300_000);
            self::assertCount(1, $inbox->awaitMessages(1), 'round '.$round);
            self::assertNotNull(OrderingFixture::notificationSentAt($this->control, $orderId, 'payment_received'));
        }
    }

    /**
     * @return \Closure(): (\Closure(): string)
     */
    private static function delivery(OrderPaid $event): \Closure
    {
        return static function () use ($event): \Closure {
            $kernel = new Kernel('test', false);
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            \assert($container instanceof ContainerInterface);
            $mailer = $container->get('mailer.mailer');
            \assert($mailer instanceof MailerInterface);
            $from = $_SERVER['MAILER_FROM'] ?? $_ENV['MAILER_FROM'] ?? getenv('MAILER_FROM');
            \assert(\is_string($from));
            $container->set(SymfonyOrderStatusMailer::class, new SlowOrderStatusMailer(new SymfonyOrderStatusMailer($mailer, new PaymentReceivedText(), new OrderCancelledText(), $from)));
            $bus = $container->get('event.bus');
            \assert($bus instanceof MessageBusInterface);

            return static function () use ($bus, $event): string {
                try {
                    // As the worker hands over a message received from `events`.
                    $bus->dispatch(new Envelope($event, [new ReceivedStamp('events')]));

                    return 'handled';
                } catch (HandlerFailedException $failure) {
                    $wrapped = array_values($failure->getWrappedExceptions());

                    return 'retried: '.(1 === \count($wrapped) ? $wrapped[0]::class : $failure->getMessage());
                }
            };
        };
    }
}
