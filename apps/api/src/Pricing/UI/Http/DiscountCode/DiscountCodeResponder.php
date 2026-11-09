<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Application\Query\DiscountCodeView;
use App\Pricing\Application\Query\GetDiscountCode;
use App\Pricing\UI\Http\Promotion\PromotionResponder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The response of a code: GetDiscountCode read after the write (or for the read itself), `private, no-store`.
 */
final class DiscountCodeResponder
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function respond(string $id, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return PromotionResponder::private(new JsonResponse(DiscountCodeResponse::of($this->find($id)), $status));
    }

    /**
     * @throws HandlerFailedException of DiscountCodeNotFound (a 404) for an unknown code, also an id that is no UUID
     */
    public function find(string $id): DiscountCodeView
    {
        /** @var DiscountCodeView */
        return $this->handle(new GetDiscountCode($id));
    }
}
