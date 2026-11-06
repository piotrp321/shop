<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Cache;

use Symfony\Component\HttpFoundation\Response;

/**
 * Tags a response for Varnish: the `xkey` header with the tags separated by spaces (vmod_xkey). Varnish indexes the
 * cached object by them and removes the header before the response leaves it. Names come from CacheTags.
 */
final class CacheTagger
{
    public const string HEADER = 'xkey';

    /**
     * @param list<string> $tags
     */
    public function tag(Response $response, array $tags): void
    {
        $response->headers->set(self::HEADER, implode(' ', array_values(array_unique($tags))));
    }
}
