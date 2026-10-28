<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Infrastructure\Http\PublicCacheVarySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PublicCacheVarySubscriberTest extends TestCase
{
    public function testPublicResponseGetsVaryOrigin(): void
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'public, max-age=60');

        $this->dispatch($response);

        self::assertSame(['Origin'], $response->headers->all('Vary'));
    }

    public function testExistingVaryIsExtended(): void
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'public, max-age=60');
        $response->headers->set('Vary', 'Accept');

        $this->dispatch($response);

        self::assertSame('Accept, Origin', implode(', ', $response->headers->all('Vary')));
    }

    public function testVaryOriginIsNotDuplicated(): void
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'public, max-age=60');
        $response->headers->set('Vary', 'origin');

        $this->dispatch($response);

        self::assertSame(['origin'], $response->headers->all('Vary'));
    }

    public function testPrivateResponseIsUntouched(): void
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'private, no-store');

        $this->dispatch($response);

        self::assertFalse($response->headers->has('Vary'));
    }

    public function testSubRequestIsIgnored(): void
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'public, max-age=60');

        (new PublicCacheVarySubscriber())->onResponse(
            new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST, $response),
        );

        self::assertFalse($response->headers->has('Vary'));
    }

    private function dispatch(Response $response): void
    {
        (new PublicCacheVarySubscriber())->onResponse(
            new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $response),
        );
    }
}
