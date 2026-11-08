<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The sitemap chunk key is not one this query issued. The message never contains the submitted value.
 */
final class InvalidSitemapChunk extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The sitemap chunk key is invalid.');
    }
}
