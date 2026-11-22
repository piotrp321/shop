<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * One row of the table of the input contract (spec: docs/features/api-input-contract/spec.md, "Test kontraktu
 * wejścia"): an operation, the token it is sent with, the fixture that builds its state, the input under test with
 * a valid and an invalid value, and the exact violations the invalid value gives.
 *
 * Values may hold placeholders `{name}`: the values of the fixture and `{idempotencyKey}`, a new key in every request.
 * The path, the body and the other headers take them too.
 */
final readonly class InputCase
{
    public const string STAFF = 'staff';
    public const string CUSTOMER = 'customer';

    /**
     * @param self::STAFF|self::CUSTOMER|null $token
     * @param 'query'|'header'                $location
     * @param list<array{string, string}>     $violations property path and title of each expected violation
     * @param array<string, string>           $headers    other headers of both requests, by name (e.g. the access token
     *                                                    of a guest order)
     */
    private function __construct(
        public string $method,
        public string $path,
        public ?string $token,
        public string $fixture,
        public string $location,
        public string $input,
        public string $label,
        public ?string $valid,
        public ?string $invalid,
        public array $violations,
        public ?string $body,
        public array $headers,
    ) {
    }

    /**
     * A query parameter: both values are the query string sent as it is (already URL-encoded), e.g. `q=ab%00`,
     * `q[]=x` or `priceMin=5&priceMax=1`.
     *
     * @param string                          $operation  "METHOD /path" as in the OpenAPI documents
     * @param self::STAFF|self::CUSTOMER|null $token
     * @param list<array{string, string}>     $violations property path and title of each expected violation
     */
    public static function query(string $operation, ?string $token, string $fixture, string $parameter, string $label, string $valid, string $invalid, array $violations, ?string $body = null): self
    {
        [$method, $path] = explode(' ', $operation, 2);

        return new self($method, $path, $token, $fixture, 'query', $parameter, $label, $valid, $invalid, $violations, $body, []);
    }

    /**
     * A request header: both values are the value of the header, null sends no header.
     *
     * @param string                          $operation  "METHOD /path" as in the OpenAPI documents
     * @param self::STAFF|self::CUSTOMER|null $token
     * @param list<array{string, string}>     $violations property path and title of each expected violation
     * @param array<string, string>           $headers    other headers of both requests, by name
     */
    public static function header(string $operation, ?string $token, string $fixture, string $header, string $label, ?string $valid, ?string $invalid, array $violations, ?string $body = null, array $headers = []): self
    {
        [$method, $path] = explode(' ', $operation, 2);

        return new self($method, $path, $token, $fixture, 'header', $header, $label, $valid, $invalid, $violations, $body, $headers);
    }

    public function name(): string
    {
        return \sprintf('%s %s, %s %s: %s', $this->method, $this->path, $this->location, $this->input, $this->label);
    }
}
