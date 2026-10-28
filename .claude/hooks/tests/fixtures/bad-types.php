<?php

declare(strict_types=1);

namespace App\HookFixture;

final class BadTypes
{
    public function answer(): int
    {
        return 'not an int';
    }
}
