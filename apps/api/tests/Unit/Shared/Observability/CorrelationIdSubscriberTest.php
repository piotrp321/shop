<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Shared\Infrastructure\Observability\CorrelationIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdSubscriberTest extends TestCase
{
    private CorrelationId $correlationId;
    private CorrelationIdSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->correlationId = new CorrelationId();
        $this->subscriber = new CorrelationIdSubscriber($this->correlationId);
    }

    public function testTheRequestListenerRunsBeforeTheFirewallAndTheRateLimiter(): void
    {
        $events = CorrelationIdSubscriber::getSubscribedEvents();

        // The rate limiter listens at 4, the firewall at 8: both log or refuse, so the identifier must already be set.
        self::assertGreaterThan(8, $events[KernelEvents::REQUEST][1]);
        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
    }

    public function testAMainRequestGetsANewUuidV7AndTheResponseCarriesIt(): void
    {
        $this->subscriber->onRequest($this->requestEvent(new Request()));
        $response = new Response();
        $this->subscriber->onResponse($this->responseEvent($response));

        $id = $response->headers->get('X-Correlation-ID');
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame('7', $id[14], 'UUID version 7');
        self::assertSame($id, $this->correlationId->current());
    }

    public function testTheHeaderOfTheRequestIsIgnored(): void
    {
        $request = new Request();
        $request->headers->set('X-Correlation-ID', 'attacker-chosen');

        $this->subscriber->onRequest($this->requestEvent($request));
        $response = new Response();
        $this->subscriber->onResponse($this->responseEvent($response));

        self::assertNotSame('attacker-chosen', $response->headers->get('X-Correlation-ID'));
        self::assertNotSame('attacker-chosen', $this->correlationId->current());
    }

    public function testEachRequestGetsItsOwnIdentifierEvenWithoutAReset(): void
    {
        $this->subscriber->onRequest($this->requestEvent(new Request()));
        $first = $this->correlationId->current();
        $this->subscriber->onRequest($this->requestEvent(new Request()));

        self::assertNotNull($first);
        self::assertNotSame($first, $this->correlationId->current());
    }

    public function testASubRequestKeepsTheIdentifierOfTheMainRequest(): void
    {
        $this->subscriber->onRequest($this->requestEvent(new Request()));
        $main = $this->correlationId->current();

        $this->subscriber->onRequest($this->requestEvent(new Request(), HttpKernelInterface::SUB_REQUEST));
        $subResponse = new Response();
        $this->subscriber->onResponse($this->responseEvent($subResponse, HttpKernelInterface::SUB_REQUEST));

        self::assertSame($main, $this->correlationId->current());
        self::assertFalse($subResponse->headers->has('X-Correlation-ID'));
    }

    public function testAnExistingHeaderOfTheResponseIsReplaced(): void
    {
        $this->subscriber->onRequest($this->requestEvent(new Request()));
        $response = new Response();
        $response->headers->set('X-Correlation-ID', 'from-a-controller');

        $this->subscriber->onResponse($this->responseEvent($response));

        self::assertSame($this->correlationId->current(), $response->headers->get('X-Correlation-ID'));
    }

    public function testACommandGetsOneIdentifier(): void
    {
        $this->subscriber->onCommand($this->commandEvent('catalog:reindex'));

        self::assertNotNull($this->correlationId->current());
    }

    public function testTheWorkerDoesNotGetOneBecauseEachMessageHasItsOwn(): void
    {
        $this->subscriber->onCommand($this->commandEvent('messenger:consume'));

        self::assertNull($this->correlationId->current());
    }

    private function requestEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function responseEvent(Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), $type, $response);
    }

    private function commandEvent(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }
}
