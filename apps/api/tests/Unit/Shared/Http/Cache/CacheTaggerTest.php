<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http\Cache;

use App\Shared\Infrastructure\Http\Cache\CacheTagger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class CacheTaggerTest extends TestCase
{
    public function testSetsTheXkeyHeaderWithTheTagsSeparatedBySpaces(): void
    {
        $response = new Response();

        new CacheTagger()->tag($response, ['catalog', 'product-a', 'product-b']);

        self::assertSame('catalog product-a product-b', $response->headers->get('xkey'));
        self::assertCount(1, $response->headers->all('xkey'));
    }

    public function testARepeatedTagIsSentOnceInTheOrderOfItsFirstUse(): void
    {
        $response = new Response();

        new CacheTagger()->tag($response, ['catalog', 'product-b', 'product-a', 'product-b', 'catalog']);

        self::assertSame('catalog product-b product-a', $response->headers->get('xkey'));
    }

    public function testReplacesTagsSetBefore(): void
    {
        $response = new Response();
        $response->headers->set('xkey', 'stale');

        new CacheTagger()->tag($response, ['catalog']);

        self::assertSame('catalog', $response->headers->get('xkey'));
    }

    public function testDoesNotChangeCacheControl(): void
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge(60);

        new CacheTagger()->tag($response, ['catalog']);

        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
    }
}
