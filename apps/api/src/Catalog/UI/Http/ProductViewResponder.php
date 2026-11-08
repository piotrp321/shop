<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\GetProductEditView;
use App\Catalog\Application\Query\ProductEditView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The response of every product endpoint of the panel that answers with the product: its edit view as it is now
 * (read after the write, with the new version), never cached.
 */
final class ProductViewResponder
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function respond(string $productId, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        /** @var ProductEditView $view */
        $view = $this->handle(new GetProductEditView($productId));

        $response = new JsonResponse(ProductEditViewResponse::of($view), $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
