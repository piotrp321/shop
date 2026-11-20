<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Php;

use PHPUnit\Framework\TestCase;

/**
 * The PHP settings shared by all images (docker/php/conf.d/app.ini) keep assertions on: the code states its type
 * narrowing with \assert(), and the image has no php.ini that would say so.
 */
final class AssertionsConfigTest extends TestCase
{
    private const string FILE = __DIR__.'/../../../../docker/php/conf.d/app.ini';

    public function testAssertionsAreSwitchedOnExplicitly(): void
    {
        $settings = parse_ini_file(self::FILE, false, \INI_SCANNER_RAW);

        self::assertIsArray($settings);
        self::assertSame('1', $settings['zend.assertions'] ?? null);
    }
}
