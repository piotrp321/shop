<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategorySlugTaken;
use App\Shared\Infrastructure\Http\ProblemDetailsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The HTTP status and the type of a taken slug on the problem details mapping.
 */
final class CategorySlugTakenTest extends TestCase
{
    public function testATakenSlugIsA409ConflictWithItsDetail(): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new CategorySlugTaken());

        new ProblemDetailsSubscriber()->onException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(CategorySlugTaken::TYPE, $problem['type']);
        self::assertSame('Inna kategoria w tym miejscu drzewa ma już ten slug.', $problem['detail']);
    }
}
