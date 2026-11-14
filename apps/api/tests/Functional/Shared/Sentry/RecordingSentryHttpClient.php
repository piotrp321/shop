<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Sentry;

use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;

/**
 * The HTTP client of the Sentry SDK in the tests (config/packages/sentry.yaml): it keeps the envelopes that the real
 * transport of the SDK would send, exactly as they would leave the API, and answers 200 without any network. The
 * transport sends nothing to it without a DSN.
 */
final class RecordingSentryHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    private array $envelopes = [];

    public function sendRequest(Request $request, Options $options): Response
    {
        $this->envelopes[] = (string) $request->getStringBody();

        return new Response(200, [], '');
    }

    /**
     * @return list<string> the bodies of the requests, as sent
     */
    public function envelopes(): array
    {
        return $this->envelopes;
    }

    /**
     * @return list<array<string, mixed>> the payloads of the items of the type (event, transaction, ...) in the order sent
     */
    public function items(string $type): array
    {
        $items = [];
        foreach ($this->envelopes as $envelope) {
            $lines = explode("\n", $envelope);
            // The header of the envelope, then pairs of an item header and its payload.
            for ($i = 1; $i + 1 < \count($lines); $i += 2) {
                $header = json_decode($lines[$i], true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($header) && $type === ($header['type'] ?? null)) {
                    $payload = json_decode($lines[$i + 1], true, flags: \JSON_THROW_ON_ERROR);
                    \assert(\is_array($payload));
                    /** @var array<string, mixed> $payload */
                    $items[] = $payload;
                }
            }
        }

        return $items;
    }
}
