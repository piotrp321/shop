<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(app_sample_rate:…)%`: the sample rate of the traces of Sentry (config/packages/sentry.yaml). A number from 0 to 1
 * written as digits (`0.1`, `1`); anything else (`0,1`, an empty text, `1.5`, `1e-1`) is 0, so the SDK starts without
 * traces instead of stopping the API, the worker and the Jobs at start (issue #559). The same rule as the store and the
 * panel (`sampleRate` in their configuration of Sentry). Not `sentry_sample_rate:`: that prefix belongs to the processor
 * of the bundle, which throws on such a value.
 */
final class SentrySampleRateEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): float
    {
        $value = $getEnv($name);
        if (!\is_string($value) || 1 !== preg_match('/^\d+(\.\d+)?$/', $written = trim($value))) {
            return 0.0;
        }
        $rate = (float) $written;

        return $rate <= 1.0 ? $rate : 0.0;
    }

    public static function getProvidedTypes(): array
    {
        return ['app_sample_rate' => 'float'];
    }
}
