<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

/**
 * Sets the DEMO_PHOTOS_* variables for one test and puts the process back as it was: the environment, $_ENV and $_SERVER,
 * each with a missing key kept missing, so the next test of the process sees no value of this one.
 */
trait PointsPhotoVariables
{
    /** @var array<string, array{string|false, array{bool, mixed}, array{bool, mixed}}> */
    private array $savedVariables = [];

    protected function tearDown(): void
    {
        foreach ($this->savedVariables as $name => [$environment, $env, $server]) {
            false === $environment ? putenv($name) : putenv($name.'='.$environment);
            $env[0] ? $_ENV[$name] = $env[1] : $_ENV = array_diff_key($_ENV, [$name => true]);
            $server[0] ? $_SERVER[$name] = $server[1] : $_SERVER = array_diff_key($_SERVER, [$name => true]);
        }
        $this->savedVariables = [];
        parent::tearDown();
    }

    private function point(string $name, mixed $value): void
    {
        self::assertIsString($value);
        self::assertNotSame('', $value, $name.' of the stack is needed by the test.');
        $this->savedVariables[$name] ??= [
            getenv($name),
            [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
            [\array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
        ];
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    /** Points the variables at the Garage of the stack: an S3 endpoint like the one of R2, with the bucket of the originals. */
    private function pointAtGarage(string $bucket): void
    {
        $this->point('DEMO_PHOTOS_REGION', 'garage');
        $this->point('DEMO_PHOTOS_ENDPOINT', (string) getenv('GARAGE_S3_ENDPOINT'));
        $this->point('DEMO_PHOTOS_BUCKET', $bucket);
        $this->point('DEMO_PHOTOS_ACCESS_KEY_ID', (string) getenv('GARAGE_ACCESS_KEY_ID'));
        $this->point('DEMO_PHOTOS_SECRET_ACCESS_KEY', (string) getenv('GARAGE_SECRET_ACCESS_KEY'));
    }
}
