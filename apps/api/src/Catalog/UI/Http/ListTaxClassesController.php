<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListTaxClasses;
use App\Catalog\Application\Query\TaxClassView;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListTaxClassesController
{
    use HandleTrait;

    /** Attribute of ProductVoter (Infrastructure): the controller only knows its name. */
    private const string VIEW_PRODUCTS = 'VIEW_PRODUCTS';

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/tax-classes', name: 'catalog_backoffice_tax_classes', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listTaxClasses',
        summary: 'Tax classes',
        description: 'The tax classes a product can have, with Polish names for the product form. Every staff member may read them.',
        tags: ['Catalog'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The tax classes.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: TaxClassResponse::class))),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::VIEW_PRODUCTS)]
    public function __invoke(): JsonResponse
    {
        /** @var list<TaxClassView> $classes */
        $classes = $this->handle(new ListTaxClasses());

        $response = new JsonResponse(array_map(TaxClassResponse::of(...), $classes));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
