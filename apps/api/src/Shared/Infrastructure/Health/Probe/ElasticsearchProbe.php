<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

final class ElasticsearchProbe extends HttpProbe
{
    public function name(): string
    {
        return 'elasticsearch';
    }

    protected function path(): string
    {
        return '/_cluster/health?wait_for_status=yellow&timeout=500ms';
    }
}
