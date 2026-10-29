<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

final class ImgproxyProbe extends HttpProbe
{
    public function name(): string
    {
        return 'imgproxy';
    }

    protected function path(): string
    {
        return '/health';
    }
}
