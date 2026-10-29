<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one request and turns every failure into {@see SearchUnavailable} (transient) or {@see SearchIndexError}
 * (permanent). Messages carry the operation and Elasticsearch's error type and reason, never the request body.
 */
final class ElasticsearchErrorClassifier
{
    private const array TRANSIENT_STATUSES = [429, 502, 503, 504];
    private const string REJECTED_EXECUTION = 'es_rejected_execution_exception';
    private const int MAX_REASON_LENGTH = 300;

    /**
     * @param \Closure(): mixed $send             one synchronous call of the client
     * @param list<int>         $acceptedStatuses error statuses returned as a response instead of thrown (e.g. 404)
     *
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public static function request(string $operation, \Closure $send, array $acceptedStatuses = []): Elasticsearch
    {
        try {
            $response = $send();
        } catch (ClientResponseException|ServerResponseException $e) {
            $httpResponse = $e->getResponse();
            if (\in_array($httpResponse->getStatusCode(), $acceptedStatuses, true)) {
                $response = new Elasticsearch();
                $response->setResponse($httpResponse, false);

                return $response;
            }

            throw self::fromResponse($operation, $httpResponse, $e);
        } catch (NetworkExceptionInterface|NoNodeAvailableException $e) {
            // The transport wraps the network error in "Exceeded maximum number of retries"; report the cause.
            $cause = $e instanceof NoNodeAvailableException ? ($e->getPrevious() ?? $e) : $e;

            throw new SearchUnavailable(\sprintf('Elasticsearch is unreachable (%s): %s', $operation, $cause->getMessage()), 0, $e);
        } catch (SearchUnavailable|SearchIndexError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SearchIndexError(\sprintf('Elasticsearch request failed (%s): %s', $operation, $e->getMessage()), 0, $e);
        }

        if (!$response instanceof Elasticsearch) {
            throw new \LogicException('Only synchronous Elasticsearch requests are supported.');
        }

        return $response;
    }

    public static function isTransient(int $status, ?string $errorType): bool
    {
        return \in_array($status, self::TRANSIENT_STATUSES, true) || self::REJECTED_EXECUTION === $errorType;
    }

    /**
     * "type: reason" of an Elasticsearch error object, shortened.
     */
    public static function describe(mixed $error): string
    {
        if (!\is_array($error)) {
            return \is_string($error) ? self::shorten($error) : 'unknown error';
        }
        $type = \is_string($error['type'] ?? null) ? $error['type'] : 'unknown';
        $reason = \is_string($error['reason'] ?? null) ? $error['reason'] : '';

        return self::shorten('' === $reason ? $type : $type.': '.$reason);
    }

    public static function errorType(mixed $error): ?string
    {
        return \is_array($error) && \is_string($error['type'] ?? null) ? $error['type'] : null;
    }

    private static function fromResponse(string $operation, ResponseInterface $response, \Throwable $previous): SearchUnavailable|SearchIndexError
    {
        $status = $response->getStatusCode();
        $error = self::errorOf($response);
        $message = \sprintf('Elasticsearch rejected the request (%s): %d %s', $operation, $status, self::describe($error));

        return self::isTransient($status, self::errorType($error))
            ? new SearchUnavailable($message, 0, $previous)
            : new SearchIndexError($message, 0, $previous);
    }

    private static function errorOf(ResponseInterface $response): mixed
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        try {
            $decoded = json_decode($body->getContents(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? ($decoded['error'] ?? null) : null;
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > self::MAX_REASON_LENGTH ? mb_substr($text, 0, self::MAX_REASON_LENGTH).'…' : $text;
    }
}
