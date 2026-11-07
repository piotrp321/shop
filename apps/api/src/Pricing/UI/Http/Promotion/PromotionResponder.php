<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\GetPromotion;
use App\Pricing\Application\Query\PromotionDetails;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The response of a promotion: GetPromotion read after the write (or for the read itself), `private, no-store`.
 */
final class PromotionResponder
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function respond(string $id, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        /** @var PromotionDetails $promotion */
        $promotion = $this->handle(new GetPromotion($id));

        return self::private(new JsonResponse(PromotionResponse::of($promotion), $status));
    }

    public static function private(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
