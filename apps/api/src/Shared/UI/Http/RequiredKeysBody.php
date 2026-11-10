<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

/**
 * Marks the argument of a controller that takes the JSON body of the request with every key required: the body is read
 * by RequiredKeysBodyResolver into the class of the argument, whose constructor parameters are the keys. Used instead
 * of #[MapRequestPayload] with AbstractNormalizer::REQUIRE_ALL_PROPERTIES, whose violation of a missing key is the
 * serializer's English "This value should be of type mixed.". It is a MapRequestPayload that names another resolver, so
 * the OpenAPI describer of the request body (NelmioApiDocBundle) sees it as before.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class RequiredKeysBody extends MapRequestPayload
{
    public function __construct()
    {
        parent::__construct(acceptFormat: 'json', resolver: RequiredKeysBodyResolver::class, mapWhenEmpty: true);
    }
}
